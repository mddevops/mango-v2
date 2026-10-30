<?php

namespace Tests\Feature\BusinessDay;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Showroom;
use App\Services\BusinessDayCalculator;
use App\Support\PermissionCatalog as P;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

class BusinessDaySettingsTest extends TestCase
{
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_new_showroom_defaults_to_enabled_19_00_boundary(): void
    {
        $this->actingAs($this->administrator())
            ->post(route('showrooms.store'), ['name' => 'Юг', 'timezone' => 'Europe/Moscow'])
            ->assertSessionHasNoErrors();

        $showroom = Showroom::query()->where('name', 'Юг')->sole();
        $this->assertSame('Europe/Moscow', $showroom->timezone);
        $this->assertTrue($showroom->business_day_boundary_enabled);
        $this->assertSame('19:00', $showroom->business_day_boundary);
    }

    public function test_administrator_creates_and_changes_showroom_business_day(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('showrooms.store'), ['name' => 'Север', 'timezone' => 'Asia/Yekaterinburg', 'business_day_boundary_enabled' => true, 'business_day_boundary' => '20:30'])
            ->assertSessionHasNoErrors();

        $showroom = Showroom::query()->where('name', 'Север')->sole();
        $this->assertSame('20:30', $showroom->business_day_boundary);
        $this->assertSame('20:30', AuditLog::query()->where('event', 'showroom.created')->sole()->changes['business_day_boundary']);

        $this->actingAs($admin)
            ->put(route('showrooms.update', $showroom), $this->showroomPayload($showroom, ['business_day_boundary_enabled' => false, 'business_day_boundary' => null]))
            ->assertSessionHasNoErrors();

        $showroom->refresh();
        $this->assertFalse($showroom->business_day_boundary_enabled);
        $this->assertSame('20:30', $showroom->business_day_boundary);
        $this->assertSame(1, $showroom->lock_version);
        $this->assertSame([true, false], AuditLog::query()->where('event', 'showroom.updated')->sole()->changes['business_day_boundary_enabled']);
    }

    public function test_stale_showroom_form_is_rejected(): void
    {
        $showroom = Showroom::factory()->create();
        $payload = $this->showroomPayload($showroom, ['business_day_boundary' => '18:00']);
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->put(route('showrooms.update', $showroom), $payload)
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put(route('showrooms.update', $showroom), [...$payload, 'business_day_boundary' => '21:00'])
            ->assertSessionHasErrors('lock_version');

        $this->assertSame('18:00', $showroom->fresh()->business_day_boundary);
    }

    public function test_showroom_business_day_is_validated(): void
    {
        $showroom = Showroom::factory()->create();
        $admin = $this->administrator();

        foreach (['24:00', '9:00', '19:00:00', '19-00', 'abc'] as $time) {
            $this->actingAs($admin)
                ->put(route('showrooms.update', $showroom), $this->showroomPayload($showroom, ['business_day_boundary' => $time]))
                ->assertSessionHasErrors(['business_day_boundary' => 'Укажите время в формате ЧЧ:ММ, от 00:00 до 23:59.']);
        }

        $this->actingAs($admin)
            ->put(route('showrooms.update', $showroom), $this->showroomPayload($showroom, ['business_day_boundary_enabled' => true, 'business_day_boundary' => '']))
            ->assertSessionHasErrors(['business_day_boundary' => 'Укажите время границы расчётного дня.']);

        $this->actingAs($admin)
            ->put(route('showrooms.update', $showroom), $this->showroomPayload($showroom, ['business_day_boundary_enabled' => 'maybe']))
            ->assertSessionHasErrors('business_day_boundary_enabled');

        $this->actingAs($admin)
            ->put(route('showrooms.update', $showroom), $this->showroomPayload($showroom, ['timezone' => 'Europe/Berlin']))
            ->assertSessionHasErrors(['timezone' => 'Выберите часовой пояс из списка.']);

        $this->assertSame('19:00', $showroom->fresh()->business_day_boundary);
        $this->assertSame(0, $showroom->fresh()->lock_version);
    }

    public function test_source_uses_showroom_rule_despite_stored_override(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        DB::table('sources')->where('id', $source->id)->update(['business_day_mode' => 'custom', 'business_day_boundary' => '21:00']);
        $calculator = app(BusinessDayCalculator::class);
        $moment = CarbonImmutable::parse('2026-10-21 19:30:00', 'Europe/Moscow');

        $day = $calculator->forSource($source->fresh(), $moment);
        $this->assertSame('2026-10-22', $day->date);
        $this->assertSame(['timezone' => 'Europe/Moscow', 'boundary' => '19:00'], $day->rule->snapshot());

        $this->actingAs($this->administrator())
            ->put(route('showrooms.update', $showroom), $this->showroomPayload($showroom, ['timezone' => 'Asia/Yekaterinburg', 'business_day_boundary' => '20:00']))
            ->assertSessionHasNoErrors();

        $day = $calculator->forSource($source->fresh(), $moment);
        $this->assertSame('2026-10-22', $day->date);
        $this->assertSame(['timezone' => 'Asia/Yekaterinburg', 'boundary' => '20:00'], $day->rule->snapshot());
        $this->assertSame('2026-10-21', $calculator->forSource($source->fresh(), CarbonImmutable::parse('2026-10-21 19:59:59', 'Asia/Yekaterinburg'))->date);

        $this->actingAs($this->administrator())
            ->put(route('showrooms.update', $showroom), $this->showroomPayload($showroom, ['business_day_boundary_enabled' => false]))
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-21', $calculator->forSource($source->fresh(), CarbonImmutable::parse('2026-10-21 23:00:00', 'Asia/Yekaterinburg'))->date);
        $this->assertDatabaseHas('sources', ['id' => $source->id, 'business_day_mode' => 'custom', 'business_day_boundary' => '21:00']);
    }

    public function test_source_requests_and_pages_have_no_business_day_settings(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->put(route('sources.update', $source), $this->updateSourcePayload($source, [
                'name' => 'Новое имя',
                'business_day_mode' => 'custom',
                'business_day_boundary' => '10:00',
                'business_day_boundary_enabled' => false,
                'timezone' => 'Asia/Kamchatka',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sources', ['id' => $source->id, 'name' => 'Новое имя', 'business_day_mode' => 'showroom', 'business_day_boundary' => null]);
        $showroom->refresh();
        $this->assertTrue($showroom->business_day_boundary_enabled);
        $this->assertSame('19:00', $showroom->business_day_boundary);
        $this->assertSame('Europe/Moscow', $showroom->timezone);
        $this->assertArrayNotHasKey('business_day_mode', AuditLog::query()->where('event', 'source.updated')->sole()->changes);

        $this->actingAs($admin)
            ->get(route('sources.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('businessDayModes')
                ->missing('sources.data.0.business_day')
                ->where('showrooms.0', ['id' => $showroom->id, 'name' => $showroom->name, 'active' => true]));
    }

    public function test_source_permission_does_not_allow_editing_showroom_settings(): void
    {
        $own = Showroom::factory()->create();

        $role = Role::query()->create(['name' => 'sources_editor', 'label' => 'Редактор источников', 'guard_name' => 'web']);
        $role->syncPermissions([P::SOURCES_VIEW, P::SOURCES_MANAGE]);
        $employee = $this->employee('sources_editor', [$own]);

        $this->actingAs($employee)
            ->put(route('showrooms.update', $own), $this->showroomPayload($own, ['business_day_boundary' => '10:00']))
            ->assertForbidden();

        $this->assertSame('19:00', $own->fresh()->business_day_boundary);
    }

    public function test_foreign_showroom_cannot_be_changed(): void
    {
        $own = Showroom::factory()->create();
        $other = Showroom::factory()->create();

        $role = Role::query()->create(['name' => 'showroom_editor', 'label' => 'Редактор салона', 'guard_name' => 'web']);
        $role->syncPermissions([P::SHOWROOMS_VIEW, P::SHOWROOMS_MANAGE]);
        $employee = $this->employee('showroom_editor', [$own]);

        $this->actingAs($employee)
            ->put(route('showrooms.update', $own), $this->showroomPayload($own, ['business_day_boundary' => '18:30']))
            ->assertSessionHasNoErrors();
        $this->assertSame('18:30', $own->fresh()->business_day_boundary);

        $this->actingAs($employee)
            ->put(route('showrooms.update', $other), $this->showroomPayload($other, ['business_day_boundary_enabled' => false, 'business_day_boundary' => '10:00']))
            ->assertForbidden();

        $other->refresh();
        $this->assertTrue($other->business_day_boundary_enabled);
        $this->assertSame('19:00', $other->business_day_boundary);
    }

    /**
     * @return array<string, mixed>
     */
    private function showroomPayload(Showroom $showroom, array $overrides = []): array
    {
        $showroom->refresh();

        return [
            'name' => $showroom->name,
            'address' => $showroom->address,
            'phone' => $showroom->phone,
            'timezone' => $showroom->timezone,
            'business_day_boundary_enabled' => $showroom->business_day_boundary_enabled,
            'business_day_boundary' => $showroom->business_day_boundary,
            'lock_version' => $showroom->lock_version,
            ...$overrides,
        ];
    }
}
