<?php

namespace Tests\Feature\Organization;

use App\Enums\DepartmentKind;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Showroom;
use App\Support\Timezones;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\TestCase;

class OrganizationStructureTest extends TestCase
{
    use BuildsOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_admin_creates_edits_and_deactivates_showroom(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('showrooms.store'), [
                'name' => 'Москва Север',
                'address' => 'ул. Северная, 1',
                'phone' => '+7 495 000-00-00',
                'timezone' => 'Europe/Moscow',
            ])
            ->assertSessionHasNoErrors();

        $showroom = Showroom::query()->where('code', 'moskva-sever')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('showrooms.update', $showroom), [
                'name' => 'Москва Север 2',
                'timezone' => 'Asia/Yekaterinburg',
                'lock_version' => $showroom->lock_version,
            ])
            ->assertSessionHasNoErrors();

        $showroom->refresh();
        $this->assertSame('moskva-sever', $showroom->code);
        $this->assertSame('Москва Север 2', $showroom->name);
        $this->assertSame('Asia/Yekaterinburg', $showroom->timezone);

        $this->actingAs($admin)
            ->patch(route('showrooms.status', $showroom), ['active' => false, 'lock_version' => $showroom->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertFalse($showroom->fresh()->active);

        $this->actingAs($admin)
            ->patch(route('showrooms.status', $showroom), ['active' => true, 'lock_version' => $showroom->fresh()->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertTrue($showroom->fresh()->active);

        foreach (['showroom.created', 'showroom.updated', 'showroom.deactivated', 'showroom.activated'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event' => $event, 'subject_id' => $showroom->id]);
        }
    }

    public function test_showroom_validation_errors_are_field_level(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('showrooms.store'), ['name' => '', 'timezone' => 'Mars/Base', 'phone' => 'abc'])
            ->assertSessionHasErrors(['name', 'timezone', 'phone']);
    }

    public function test_only_russian_timezones_are_offered_and_accepted(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->get(route('showrooms.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('timezones', fn ($zones) => collect($zones)->pluck('value')->all() === Timezones::values())
                ->where('timezones.1', ['value' => 'Europe/Moscow', 'label' => 'Москва, Санкт-Петербург, Казань (UTC+3)']));

        $this->actingAs($admin)
            ->post(route('showrooms.store'), ['name' => 'Берлин', 'timezone' => 'Europe/Berlin'])
            ->assertSessionHasErrors(['timezone' => 'Выберите часовой пояс из списка.']);
        $this->assertDatabaseMissing('showrooms', ['name' => 'Берлин']);

        $this->actingAs($admin)
            ->post(route('showrooms.store'), ['name' => 'Владивосток', 'timezone' => 'Asia/Vladivostok'])
            ->assertSessionHasNoErrors();
    }

    public function test_showroom_with_legacy_timezone_stays_editable(): void
    {
        $admin = $this->administrator();
        $legacy = Showroom::factory()->create(['timezone' => 'Europe/Volgograd']);

        $this->actingAs($admin)
            ->put(route('showrooms.update', $legacy), ['name' => 'Волгоград', 'timezone' => 'Europe/Volgograd', 'lock_version' => $legacy->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertSame('Europe/Volgograd', $legacy->fresh()->timezone);

        $this->actingAs($admin)
            ->put(route('showrooms.update', $legacy), ['name' => 'Волгоград', 'timezone' => 'Europe/Berlin', 'lock_version' => $legacy->fresh()->lock_version])
            ->assertSessionHasErrors('timezone');
    }

    public function test_showroom_list_is_paginated_on_server(): void
    {
        $admin = $this->administrator();
        Showroom::factory()->count(12)->create();

        $this->actingAs($admin)
            ->get(route('showrooms.index', ['page' => 2, 'per_page' => 10]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('showrooms.data', 2)
                ->where('showrooms.meta.total', 12)
                ->where('showrooms.meta.current_page', 2)
                ->where('showrooms.meta.last_page', 2));
    }

    public function test_showroom_department_requires_showroom_and_shared_department_is_allowed(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('departments.store'), ['showroom_id' => null, 'name' => 'Продажи', 'kind' => 'showroom'])
            ->assertSessionHasErrors('showroom_id');

        $this->actingAs($admin)
            ->post(route('departments.store'), ['showroom_id' => null, 'name' => 'Кредитный отдел', 'kind' => 'credit'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('departments', ['code' => 'kreditnyy-otdel', 'showroom_id' => null, 'kind' => 'credit']);
    }

    public function test_department_cannot_be_created_in_inactive_showroom(): void
    {
        $admin = $this->administrator();
        $showroom = Showroom::factory()->inactive()->create();

        $this->actingAs($admin)
            ->post(route('departments.store'), ['showroom_id' => $showroom->id, 'name' => 'Продажи', 'kind' => 'showroom'])
            ->assertSessionHasErrors('showroom_id');
    }

    public function test_department_code_is_unique_within_scope_including_shared_null_scope(): void
    {
        $admin = $this->administrator();
        [$first, $second] = Showroom::factory()->count(2)->create();

        foreach ([$first, $second, $first] as $showroom) {
            $this->actingAs($admin)
                ->post(route('departments.store'), ['showroom_id' => $showroom->id, 'name' => 'Продажи', 'kind' => 'showroom'])
                ->assertSessionHasNoErrors();
        }

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($admin)
                ->post(route('departments.store'), ['showroom_id' => null, 'name' => 'Продажи', 'kind' => 'other'])
                ->assertSessionHasNoErrors();
        }

        $this->assertEqualsCanonicalizing(['prodazhi', 'prodazhi-2'], $first->departments()->pluck('code')->all());
        $this->assertSame(['prodazhi'], $second->departments()->pluck('code')->all());
        $this->assertEqualsCanonicalizing(
            ['call_center', 'prodazhi', 'prodazhi-2'],
            Department::query()->whereNull('showroom_id')->pluck('code')->all(),
        );
    }

    public function test_database_rejects_duplicate_shared_department_code_despite_null(): void
    {
        Department::factory()->shared()->create(['code' => 'dup']);

        $this->expectException(UniqueConstraintViolationException::class);

        Department::factory()->shared(DepartmentKind::Other)->create(['code' => 'dup']);
    }

    public function test_second_department_of_existing_kind_needs_no_code_changes(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('departments.store'), ['showroom_id' => null, 'name' => 'Второй колл-центр', 'kind' => 'call_center'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Department::query()->where('kind', 'call_center')->count());
    }

    public function test_department_update_changes_only_name(): void
    {
        $admin = $this->administrator();
        $showroom = Showroom::factory()->create();
        $other = Showroom::factory()->create();
        $department = Department::factory()->for($showroom)->create(['code' => 'sales']);

        $this->actingAs($admin)
            ->put(route('departments.update', $department), [
                'name' => 'Взломанный',
                'code' => 'hacked',
                'showroom_id' => $other->id,
                'lock_version' => $department->lock_version,
            ])
            ->assertSessionHasErrors(['code', 'showroom_id']);
        $this->assertSame('sales', $department->fresh()->code);

        $this->actingAs($admin)
            ->put(route('departments.update', $department), [
                'name' => 'Новый отдел продаж',
                'kind' => 'credit',
                'lock_version' => $department->lock_version,
            ])
            ->assertSessionHasNoErrors();

        $department->refresh();
        $this->assertSame('Новый отдел продаж', $department->name);
        $this->assertSame('sales', $department->code);
        $this->assertSame(DepartmentKind::Showroom, $department->kind);
        $this->assertSame($showroom->id, $department->showroom_id);
    }

    public function test_employee_cannot_join_department_of_showroom_without_access(): void
    {
        $admin = $this->administrator();
        [$first, $second] = Showroom::factory()->count(2)->create();
        $department = Department::factory()->for($second)->create();
        $user = $this->employee('operator', [$first]);

        $this->actingAs($admin)
            ->put(route('users.update', $user), [...$this->updatePayload($user), 'department_ids' => [$department->id]])
            ->assertSessionHasErrors('department_ids');

        $this->actingAs($admin)
            ->put(route('users.update', $user), [
                ...$this->updatePayload($user),
                'showroom_ids' => [$first->id, $second->id],
                'department_ids' => [$department->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([$department->id], $user->fresh()->departments()->pluck('departments.id')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.showrooms_changed', 'subject_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.departments_changed', 'subject_id' => $user->id]);
    }

    public function test_showroom_with_history_cannot_be_deleted_physically(): void
    {
        $showroom = Showroom::factory()->create();
        Department::factory()->for($showroom)->create();

        $this->expectException(QueryException::class);

        $showroom->delete();
    }

    public function test_audit_shows_only_entries_of_accessible_showrooms(): void
    {
        $admin = $this->administrator();
        [$own, $foreign] = Showroom::factory()->count(2)->create();

        foreach ([$own, $foreign] as $showroom) {
            $this->actingAs($admin)
                ->put(route('showrooms.update', $showroom), [
                    'name' => 'Переименован '.$showroom->id,
                    'timezone' => 'Europe/Moscow',
                    'lock_version' => $showroom->lock_version,
                ])
                ->assertSessionHasNoErrors();
        }

        $director = $this->employee('director', [$own]);

        $this->actingAs($director)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data', fn ($rows) => collect($rows)->isNotEmpty()
                    && collect($rows)->every(fn ($row) => $row['subject'] === 'Переименован '.$own->id)));

        $this->assertTrue(AuditLog::query()->where('subject_label', 'Переименован '.$foreign->id)->exists());
    }

    public function test_audit_never_stores_passwords_or_tokens(): void
    {
        $admin = $this->administrator();
        $showroom = Showroom::factory()->create();

        $this->actingAs($admin)
            ->post(route('users.store'), $this->userPayload([
                'email' => 'audit@example.com',
                'password' => 'Very-Secret-Pass-789',
                'password_confirmation' => 'Very-Secret-Pass-789',
                'showroom_ids' => [$showroom->id],
            ]))
            ->assertSessionHasNoErrors();

        $dump = json_encode(AuditLog::query()->get()->toArray());

        $this->assertStringNotContainsString('Very-Secret-Pass-789', $dump);
        $this->assertStringNotContainsString('"password"', $dump);
        $this->assertStringNotContainsString('remember_token', $dump);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.created']);
    }
}
