<?php

namespace Tests\Feature\Sources;

use App\Models\AuditLog;
use App\Models\ContactChannel;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\Source;
use App\Support\PermissionCatalog as P;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

class SourcesTest extends TestCase
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

    public function test_administrator_creates_site_with_several_channels_and_generated_code(): void
    {
        $showroom = Showroom::factory()->create();

        $this->actingAs($this->administrator())
            ->post(route('sources.store'), $this->sourcePayload($showroom, [
                'channel_ids' => $this->channels('callback-request', 'direct-call', 'chat'),
            ]))
            ->assertSessionHasNoErrors();

        $source = Source::query()->sole();
        $this->assertSame('sayt-salona', $source->code);
        $this->assertSame($this->kind('site')->id, $source->source_kind_id);
        $this->assertSame(3, $source->channels()->count());
        $this->assertNull($source->legacy_classification_id);

        $log = AuditLog::query()->where('event', 'source.created')->sole();
        $this->assertSame('Сайт', $log->changes['kind']);
        $this->assertSame('Обратный звонок, Прямой звонок, Чат', $log->changes['channels']);
    }

    public function test_retired_form_channel_cannot_be_chosen(): void
    {
        $showroom = Showroom::factory()->create();

        $this->actingAs($this->administrator())
            ->post(route('sources.store'), $this->sourcePayload($showroom, [
                'channel_ids' => $this->channels('callback-request', 'form'),
            ]))
            ->assertSessionHasErrors(['channel_ids' => 'Канал «Форма/заявка» отключён.']);

        $this->assertSame(0, Source::query()->count());
    }

    public function test_code_cannot_be_set_by_client(): void
    {
        $showroom = Showroom::factory()->create();

        $this->actingAs($this->administrator())
            ->post(route('sources.store'), $this->sourcePayload($showroom, ['code' => 'custom']))
            ->assertSessionHasErrors('code');
    }

    public function test_bot_source_without_url_and_classification(): void
    {
        $showroom = Showroom::factory()->create();

        $this->actingAs($this->administrator())
            ->post(route('sources.store'), $this->sourcePayload($showroom, [
                'name' => 'Telegram-бот',
                'source_kind_id' => $this->kind('bot')->id,
                'source_classification_id' => null,
                'url' => null,
                'channel_ids' => $this->channels('chat'),
            ]))
            ->assertSessionHasNoErrors();

        $source = Source::query()->sole();
        $this->assertNull($source->url);
        $this->assertNull($source->source_classification_id);
        $this->assertSame($this->kind('bot')->id, $source->source_kind_id);
    }

    public function test_kind_rules_limit_classifications_and_channels(): void
    {
        $showroom = Showroom::factory()->create();
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('sources.store'), $this->sourcePayload($showroom, [
                'source_kind_id' => $this->kind('quiz')->id,
                'channel_ids' => $this->channels('callback-request', 'chat'),
            ]))
            ->assertSessionHasErrors(['channel_ids' => 'Канал «Чат» не разрешён для вида «Квиз».']);

        $this->actingAs($admin)
            ->post(route('sources.store'), $this->sourcePayload($showroom, [
                'source_classification_id' => $this->classification('va')->id,
            ]))
            ->assertSessionHasErrors(['source_classification_id' => 'Классификация «VA» не разрешена для вида «Сайт».']);

        $this->actingAs($admin)
            ->post(route('sources.store'), $this->sourcePayload($showroom, [
                'source_kind_id' => $this->kind('partner')->id,
                'source_classification_id' => $this->classification('seo')->id,
            ]))
            ->assertSessionHasErrors('source_classification_id');

        $this->actingAs($admin)
            ->post(route('sources.store'), $this->sourcePayload($showroom, [
                'source_kind_id' => $this->kind('quiz')->id,
                'source_classification_id' => $this->classification('seo')->id,
                'channel_ids' => $this->channels('callback-request'),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Source::query()->count());
    }

    public function test_changed_kind_rules_apply_to_new_choices_only(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom, [
            'source_classification_id' => $this->classification('va')->id,
            'channel_ids' => $this->channels('chat'),
        ]);
        $this->kind('site')->channels()->detach($this->channels('chat'));
        ContactChannel::query()->where('code', 'chat')->update(['active' => false]);

        $this->actingAs($this->administrator())
            ->put(route('sources.update', $source), $this->updateSourcePayload($source, ['name' => 'Переименован']))
            ->assertSessionHasNoErrors();

        $source->refresh();
        $this->assertSame('Переименован', $source->name);
        $this->assertSame($this->classification('va')->id, $source->source_classification_id);
        $this->assertSame($this->channels('chat'), $source->channels()->pluck('contact_channels.id')->all());

        $this->actingAs($this->administrator())
            ->put(route('sources.update', $source), $this->updateSourcePayload($source, ['source_kind_id' => $this->kind('bot')->id]))
            ->assertSessionHasErrors(['source_classification_id', 'channel_ids']);
    }

    public function test_disable_and_enable_source_and_stale_edit_is_rejected(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $admin = $this->administrator();

        $stale = $this->updateSourcePayload($source, ['name' => 'Старая форма']);

        $this->actingAs($admin)
            ->patch(route('sources.status', $source), ['active' => false, 'lock_version' => $source->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertFalse($source->fresh()->active);

        $this->actingAs($admin)
            ->put(route('sources.update', $source), $stale)
            ->assertSessionHasErrors('lock_version');
        $this->assertNotSame('Старая форма', $source->fresh()->name);

        $this->actingAs($admin)
            ->patch(route('sources.status', $source), ['active' => true, 'lock_version' => $source->fresh()->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertTrue($source->fresh()->active);
    }

    public function test_employee_sees_and_changes_only_sources_of_own_showrooms(): void
    {
        $own = Showroom::factory()->create(['name' => 'Свой']);
        $other = Showroom::factory()->create(['name' => 'Чужой']);
        $ownSource = $this->makeSource($own, ['name' => 'Мой сайт']);
        $otherSource = $this->makeSource($other, ['name' => 'Чужой сайт']);

        $role = Role::query()->create(['name' => 'sources_editor', 'label' => 'Редактор источников', 'guard_name' => 'web']);
        $role->syncPermissions([P::SOURCES_VIEW, P::SOURCES_MANAGE]);
        $employee = $this->employee('sources_editor', [$own]);

        $this->actingAs($employee)
            ->get(route('sources.index', ['showroom' => $other->id, 'search' => 'сайт']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('sources/index')
                ->where('filters.filter', [['field' => 'search', 'operator' => 'contains', 'values' => ['сайт']]])
                ->has('sources.data', 1)
                ->where('sources.data.0.id', $ownSource->id)
                ->where('sources.meta.total', 1)
                ->has('showrooms', 1)
                ->where('showrooms.0.id', $own->id)
                ->where('can.manageConnections', false)
                ->where('mangoConnections', []));

        $this->actingAs($employee)
            ->post(route('sources.store'), $this->sourcePayload($other))
            ->assertForbidden();

        $this->actingAs($employee)
            ->put(route('sources.update', $otherSource), $this->updateSourcePayload($otherSource, ['name' => 'Взлом']))
            ->assertForbidden();

        $this->actingAs($employee)
            ->put(route('sources.update', $ownSource), $this->updateSourcePayload($ownSource, ['showroom_id' => $other->id]))
            ->assertForbidden();

        $this->actingAs($employee)
            ->patch(route('sources.status', $otherSource), ['active' => false, 'lock_version' => $otherSource->lock_version])
            ->assertForbidden();

        $this->actingAs($employee)
            ->put(route('sources.update', $ownSource), $this->updateSourcePayload($ownSource, ['connections' => ['site_intake' => true]]))
            ->assertForbidden();

        $this->actingAs($employee)
            ->put(route('sources.update', $ownSource), $this->updateSourcePayload($ownSource, ['name' => 'Мой сайт 2']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Чужой сайт', $otherSource->fresh()->name);
        $this->assertSame($other->id, $otherSource->fresh()->showroom_id);
        $this->assertTrue($otherSource->fresh()->active);
        $this->assertSame('Мой сайт 2', $ownSource->fresh()->name);
    }

    public function test_viewer_without_manage_permission_cannot_change_sources(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $viewer = $this->employee('director', [$showroom]);

        $this->actingAs($viewer)->get(route('sources.index'))->assertOk();
        $this->actingAs($viewer)->post(route('sources.store'), $this->sourcePayload($showroom))->assertForbidden();
        $this->actingAs($viewer)
            ->patch(route('sources.status', $source), ['active' => false, 'lock_version' => 0])
            ->assertForbidden();
    }

    public function test_filters_by_kind_classification_and_channel(): void
    {
        $showroom = Showroom::factory()->create();
        $site = $this->makeSource($showroom, ['name' => 'Сайт']);
        $bot = $this->makeSource($showroom, [
            'name' => 'Бот',
            'source_kind_id' => $this->kind('bot')->id,
            'source_classification_id' => null,
            'channel_ids' => $this->channels('chat'),
        ]);
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->get(route('sources.index', ['kind' => $this->kind('bot')->id]))
            ->assertInertia(fn (Assert $page) => $page->has('sources.data', 1)->where('sources.data.0.id', $bot->id));

        $this->actingAs($admin)
            ->get(route('sources.index', ['classification' => 'none']))
            ->assertInertia(fn (Assert $page) => $page->has('sources.data', 1)->where('sources.data.0.id', $bot->id));

        $this->actingAs($admin)
            ->get(route('sources.index', ['channel' => $this->channels('direct-call')[0]]))
            ->assertInertia(fn (Assert $page) => $page->has('sources.data', 1)->where('sources.data.0.id', $site->id));
    }

    public function test_old_links_redirect_to_new_pages(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)->get('/sites')->assertRedirect('/sources');
        $this->actingAs($admin)->get('/site-types')->assertRedirect('/source-references/classifications');
        $this->actingAs($admin)->get('/agencies')->assertRedirect('/source-references/agencies');
    }
}
