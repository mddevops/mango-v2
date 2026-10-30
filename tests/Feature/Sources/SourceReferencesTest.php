<?php

namespace Tests\Feature\Sources;

use App\Enums\IconColor;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\ContactChannel;
use App\Models\Showroom;
use App\Models\SourceClassification;
use App\Models\SourceKind;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

class SourceReferencesTest extends TestCase
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

    public function test_initial_values_and_kind_rules(): void
    {
        $this->assertSame(
            ['Сайт', 'Квиз', 'Бот', 'Соцсеть', 'Классифайд', 'Партнёр', 'Ручной ввод', 'Другой салон'],
            SourceKind::query()->orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertSame(
            ['Обратный звонок', 'Прямой звонок', 'Чат'],
            ContactChannel::query()->where('active', true)->orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertSame(['form'], ContactChannel::query()->where('active', false)->pluck('code')->all());
        $this->assertFalse(DB::table('source_kind_channel')->whereIn('contact_channel_id', $this->channels('form'))->exists());
        $this->assertSame(['Реклама', 'SEO', 'Реклама/SEO', 'VA'], SourceClassification::query()->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(['advertising', 'advertising-seo', 'seo'], $this->kind('quiz')->classifications()->orderBy('code')->pluck('code')->all());
        $this->assertSame(['callback-request'], $this->kind('quiz')->channels()->pluck('code')->all());
        $this->assertSame(['callback-request', 'chat', 'direct-call'], $this->kind('bot')->channels()->orderBy('code')->pluck('code')->all());
        $this->assertSame(0, $this->kind('bot')->classifications()->count());
    }

    public function test_kind_is_created_with_rules_renamed_reordered_disabled_and_enabled(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('source-references.store', 'kinds'), [
                'name' => 'Маркетплейс',
                'classification_ids' => [$this->classification('va')->id],
                'channel_ids' => $this->channels('chat'),
            ])
            ->assertSessionHasNoErrors();

        $kind = SourceKind::query()->where('name', 'Маркетплейс')->sole();
        $this->assertSame('marketpleys', $kind->code);
        $this->assertSame([$this->classification('va')->id], $kind->classifications()->pluck('source_classifications.id')->all());

        $this->actingAs($admin)
            ->put(route('source-references.update', ['kinds', $kind->id]), [
                'name' => 'Маркетплейсы',
                'sort_order' => 5,
                'classification_ids' => [],
                'channel_ids' => $this->channels('chat', 'direct-call'),
                'lock_version' => $kind->lock_version,
            ])
            ->assertSessionHasNoErrors();

        $kind->refresh();
        $this->assertSame(['Маркетплейсы', 90, 'marketpleys'], [$kind->name, $kind->sort_order, $kind->code]);
        $this->assertSame(0, $kind->classifications()->count());
        $this->assertSame(2, $kind->channels()->count());

        $this->actingAs($admin)
            ->patch(route('source-references.status', ['kinds', $kind->id]), ['active' => false, 'lock_version' => $kind->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertFalse($kind->fresh()->active);

        $this->actingAs($admin)
            ->patch(route('source-references.status', ['kinds', $kind->id]), ['active' => true, 'lock_version' => $kind->fresh()->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertTrue($kind->fresh()->active);

        $this->assertSame(
            ['source_kind.created', 'source_kind.updated', 'source_kind.deactivated', 'source_kind.activated'],
            AuditLog::query()->where('subject_type', 'source_kind')->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_dragged_rows_swap_their_places_and_the_list_is_renumbered(): void
    {
        $admin = $this->administrator();
        $lockVersion = $this->kind('bot')->lock_version;

        $this->actingAs($admin)
            ->patch(route('source-references.reorder', 'kinds'), ['ids' => [$this->kind('manual')->id, $this->kind('bot')->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['Сайт', 'Квиз', 'Ручной ввод', 'Соцсеть', 'Классифайд', 'Партнёр', 'Бот', 'Другой салон'],
            SourceKind::query()->orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertSame([10, 20, 30, 40, 50, 60, 70, 80], SourceKind::query()->orderBy('sort_order')->pluck('sort_order')->all());
        $this->assertSame($lockVersion, $this->kind('bot')->lock_version);

        $log = AuditLog::query()->where('event', 'source_kind.reordered')->sole();
        $this->assertSame(['Бот, Ручной ввод', 'Ручной ввод, Бот'], $log->changes['order']);

        $this->actingAs($admin)
            ->patch(route('source-references.reorder', 'kinds'), ['ids' => [$this->kind('site')->id, SourceKind::query()->max('id') + 1]])
            ->assertSessionHasErrors('ids');
        $this->actingAs($admin)
            ->patch(route('source-references.reorder', 'kinds'), ['ids' => [$this->kind('site')->id, $this->kind('site')->id]])
            ->assertSessionHasErrors('ids.0');
        $this->assertSame('Сайт', SourceKind::query()->orderBy('sort_order')->value('name'));
    }

    public function test_new_rule_values_must_be_active(): void
    {
        ContactChannel::query()->where('code', 'chat')->update(['active' => false]);

        $this->actingAs($this->administrator())
            ->post(route('source-references.store', 'kinds'), [
                'name' => 'Новый вид',
                'classification_ids' => [],
                'channel_ids' => $this->channels('chat'),
            ])
            ->assertSessionHasErrors('channel_ids');
    }

    public function test_classifications_channels_and_agencies_share_the_lifecycle(): void
    {
        $admin = $this->administrator();

        foreach (['classifications' => SourceClassification::class, 'channels' => ContactChannel::class, 'agencies' => Agency::class] as $type => $class) {
            $this->actingAs($admin)
                ->post(route('source-references.store', $type), ['name' => 'Значение '.$type])
                ->assertSessionHasNoErrors();

            $item = $class::query()->where('name', 'Значение '.$type)->sole();

            $this->actingAs($admin)
                ->post(route('source-references.store', $type), ['name' => 'Значение '.$type])
                ->assertSessionHasErrors('name');

            $this->actingAs($admin)
                ->put(route('source-references.update', [$type, $item->id]), ['name' => 'Новое '.$type, 'lock_version' => $item->lock_version])
                ->assertSessionHasNoErrors();

            $this->actingAs($admin)
                ->put(route('source-references.update', [$type, $item->id]), ['name' => 'Устаревшее', 'lock_version' => $item->lock_version])
                ->assertSessionHasErrors('lock_version');

            $this->actingAs($admin)
                ->patch(route('source-references.status', [$type, $item->id]), ['active' => false, 'lock_version' => $item->fresh()->lock_version])
                ->assertSessionHasNoErrors();

            $item->refresh();
            $this->assertSame(['Новое '.$type, false], [$item->name, $item->active]);
            $this->assertSame($item->id, $class::query()->orderByDesc('sort_order')->value('id'), 'A new value goes to the end of the list.');
        }

        $this->actingAs($admin)
            ->delete('/source-references/agencies/1')
            ->assertStatus(405);
    }

    public function test_kinds_and_channels_keep_a_lucide_icon_shown_in_the_orders_list_only_when_enabled(): void
    {
        $admin = $this->administrator();
        $channel = ContactChannel::query()->where('code', 'chat')->sole();

        $this->actingAs($admin)
            ->put(route('source-references.update', ['channels', $channel->id]), ['name' => $channel->name, 'icon' => 'Smartphone Charging', 'show_icon' => true, 'lock_version' => $channel->lock_version])
            ->assertSessionHasErrors('icon');
        $this->actingAs($admin)
            ->put(route('source-references.update', ['channels', $channel->id]), ['name' => $channel->name, 'icon' => 'smartphone-charging', 'icon_color' => 'neon', 'show_icon' => true, 'lock_version' => $channel->lock_version])
            ->assertSessionHasErrors('icon_color');
        $this->actingAs($admin)
            ->put(route('source-references.update', ['channels', $channel->id]), ['name' => $channel->name, 'icon' => 'smartphone-charging', 'icon_color' => 'blue', 'show_icon' => true, 'lock_version' => $channel->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertSame(['smartphone-charging', true, IconColor::Blue], [$channel->fresh()->icon, $channel->fresh()->show_icon, $channel->fresh()->icon_color]);
        $changes = AuditLog::query()->where('event', 'contact_channel.updated')->latest('id')->sole()->changes;
        $this->assertSame([null, 'smartphone-charging'], $changes['icon']);
        $this->assertSame([false, true], $changes['show_icon']);
        $this->assertSame(['gray', 'blue'], $changes['icon_color']);

        $this->actingAs($admin)
            ->post(route('source-references.store', 'kinds'), ['name' => 'Маркетплейс', 'icon' => 'store', 'icon_color' => 'amber', 'show_icon' => false, 'classification_ids' => [], 'channel_ids' => []])
            ->assertSessionHasNoErrors();
        $this->assertSame(['store', false, IconColor::Amber], [SourceKind::query()->where('name', 'Маркетплейс')->value('icon'), (bool) SourceKind::query()->where('name', 'Маркетплейс')->value('show_icon'), SourceKind::query()->where('name', 'Маркетплейс')->value('icon_color')]);

        $this->actingAs($admin)
            ->put(route('source-references.update', ['channels', $channel->id]), ['name' => $channel->name, 'icon' => null, 'show_icon' => true, 'lock_version' => $channel->fresh()->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertSame([null, false], [$channel->fresh()->icon, $channel->fresh()->show_icon], 'Without an icon there is nothing to show.');

        $this->actingAs($admin)
            ->post(route('source-references.store', 'classifications'), ['name' => 'С иконкой', 'icon' => 'store'])
            ->assertSessionHasNoErrors();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('source_classifications', 'icon'));
    }

    public function test_managing_references_requires_permission_and_global_access(): void
    {
        $showroom = Showroom::factory()->create();
        $localAdmin = $this->employee('administrator', [$showroom]);
        $director = $this->employee('director', [$showroom]);

        $this->actingAs($localAdmin)->get(route('source-references.index', 'kinds'))->assertOk();
        $this->actingAs($localAdmin)
            ->post(route('source-references.store', 'channels'), ['name' => 'Новый'])
            ->assertForbidden();
        $this->actingAs($localAdmin)
            ->patch(route('source-references.reorder', 'kinds'), ['ids' => [$this->kind('quiz')->id, $this->kind('site')->id]])
            ->assertForbidden();
        $this->actingAs($director)->get(route('source-references.index', 'kinds'))->assertForbidden();
        $this->actingAs($this->administrator())->get('/source-references/unknown')->assertNotFound();
    }

    public function test_index_lists_values_with_usage(): void
    {
        $showroom = Showroom::factory()->create();
        $this->makeSource($showroom);

        $this->actingAs($this->administrator())
            ->get(route('source-references.index', ['kinds', 'search' => 'сайт']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('source-references/index')
                ->where('type', 'kinds')
                ->has('items.data', 1)
                ->where('items.data.0.sources_count', 1)
                ->has('tabs', 4)
                ->has('channels', 4)
                ->where('can.manage', true));
    }

    public function test_usage_counts_only_sources_of_accessible_showrooms(): void
    {
        $own = Showroom::factory()->create();
        $foreign = Showroom::factory()->create();
        $this->makeSource($own);
        $this->makeSource($foreign);
        $this->makeSource($foreign);

        $this->actingAs($this->employee('administrator', [$own]))
            ->get(route('source-references.index', ['channels', 'search' => 'Прямой']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.sources_count', 1));

        $this->actingAs($this->administrator())
            ->get(route('source-references.index', ['channels', 'search' => 'Прямой']))
            ->assertInertia(fn (Assert $page) => $page->where('items.data.0.sources_count', 3));
    }
}
