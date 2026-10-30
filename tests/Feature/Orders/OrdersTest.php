<?php

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientPhone;
use App\Models\Order;
use App\Models\OrderComment;
use App\Models\OrderStatus;
use App\Models\OrderTrashReason;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\User;
use App\Services\OrderManager;
use App\Support\Phone;
use App\Support\PermissionCatalog as P;
use Carbon\CarbonImmutable;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

class OrdersTest extends TestCase
{
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    private const WORKER = [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_CREATE, P::ORDERS_UPDATE, P::ORDERS_COMMENT];

    private int $phoneSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_phone_normalization_accepts_full_russian_numbers_only(): void
    {
        foreach (['89515346783', '+7 951 534-67-83', '7 (951) 534 67 83', '+7(951)5346783'] as $input) {
            $this->assertSame('79515346783', Phone::normalize($input), $input);
        }

        foreach (['6783', '9515346783', '+8 951 534-67-83', '79515346783 доб. 1', '795153467831', '', null] as $input) {
            $this->assertNull(Phone::normalize($input), (string) $input);
        }

        $this->assertSame('+7 951 534-67-83', Phone::format('79515346783'));
    }

    public function test_manual_order_links_client_by_any_full_phone_and_counts_repeats_per_showroom(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 21:30:00');
        $first = Showroom::factory()->create(['timezone' => 'Europe/Moscow', 'business_day_boundary_enabled' => true, 'business_day_boundary' => '20:00']);
        $second = Showroom::factory()->create(['timezone' => 'Europe/Moscow']);
        $firstSource = $this->makeSource($first);
        $secondSource = $this->makeSource($second);
        $admin = $this->administrator();

        $one = $this->createOrder($admin, $firstSource, ['contact_name' => 'Иван', 'phones' => ['8 951 534-67-83', '+7 900 000-00-01']]);
        $two = $this->createOrder($admin, $firstSource, ['contact_name' => 'Другое имя', 'phones' => ['+7 (900) 000-00-01']]);
        $other = $this->createOrder($admin, $secondSource, ['phones' => ['79515346783']]);
        $three = $this->createOrder($admin, $firstSource, ['phones' => ['+7 951 534 67 83', '+7 900 000-00-02']]);

        $this->assertSame(1, Client::query()->count());
        $this->assertSame('Иван', Client::query()->value('name'));
        $this->assertEqualsCanonicalizing(['79515346783', '79000000001', '79000000002'], ClientPhone::query()->pluck('phone')->all());
        $this->assertSame([0, 1, 2], [$one->repeat_number, $two->repeat_number, $three->repeat_number]);
        $this->assertSame(0, $other->repeat_number);
        $this->assertSame('Другое имя', $two->contact_name);
        $this->assertSame('79000000001', $two->phone);
        $this->assertSame(0, $two->additionalPhones()->count());
        $this->assertSame('79515346783', $one->phone);
        $this->assertSame(['79000000001'], $one->additionalPhones()->pluck('phone')->all());

        $this->assertSame($first->id, $one->showroom_id);
        $this->assertSame('manual', $one->origin->value);
        $this->assertSame('2026-10-23', $one->business_date);
        $this->assertEquals(['timezone' => 'Europe/Moscow', 'boundary' => '20:00'], $one->business_day_rule);
        $this->assertSame('2026-10-22 21:30:00', $one->event_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-22 21:30:00', $one->received_at->format('Y-m-d H:i:s'));
        $this->assertSame('Новая', $one->status->name);
        $this->assertSame($admin->id, $one->responsible_id);

        $first->update(['business_day_boundary_enabled' => false]);
        $this->actingAs($admin)->put(route('orders.update', $one->id), $this->updatePayloadFor($one, ['contact_name' => 'Иван Петров']))->assertSessionHasNoErrors();
        $this->assertSame('2026-10-23', $one->fresh()->business_date);
    }

    public function test_create_rejects_foreign_or_inactive_source_disallowed_channel_and_showroom_override(): void
    {
        $own = Showroom::factory()->create();
        $foreign = Showroom::factory()->create();
        $source = $this->makeSource($own, ['channel_ids' => $this->channels('callback-request')]);
        $foreignSource = $this->makeSource($foreign);
        $inactive = $this->makeSource($own);
        $inactive->forceFill(['active' => false])->save();
        $employee = $this->worker([$own]);

        $this->actingAs($employee)->post(route('orders.store'), $this->createPayload($foreignSource, ['showroom_id' => $own->id]))->assertSessionHasErrors('source_id');
        $this->actingAs($employee)->post(route('orders.store'), $this->createPayload($foreignSource, ['showroom_id' => null]))->assertSessionHasErrors('source_id');
        $this->actingAs($employee)->post(route('orders.store'), $this->createPayload($inactive))->assertSessionHasErrors('source_id');
        $this->actingAs($employee)->post(route('orders.store'), $this->createPayload($source, ['contact_channel_id' => $this->channels('direct-call')[0]]))->assertSessionHasErrors('contact_channel_id');
        $this->actingAs($employee)->post(route('orders.store'), $this->createPayload($source, ['showroom_id' => $foreign->id]))->assertSessionHasErrors('showroom_id');
        $this->actingAs($employee)->post(route('orders.store'), $this->createPayload($source, ['phones' => ['6783']]))->assertSessionHasErrors('phones.0.number');
        $this->actingAs($employee)->post(route('orders.store'), $this->createPayload($source, ['phones' => ['89515346783', '+7 900', '', '', '', '', '']]))->assertSessionHasErrors('phones.1.number');
        $this->actingAs($employee)->post(route('orders.store'), $this->createPayload($source, ['phones' => ['89515346783', '+7 951 534-67-83']]))->assertSessionHasErrors('phones');

        $this->assertSame(0, Order::query()->count());

        $this->actingAs($employee)->post(route('orders.store'), $this->createPayload($source))->assertSessionHasNoErrors();
        $this->assertSame($own->id, Order::query()->value('showroom_id'));
    }

    public function test_one_showroom_needs_no_choice_and_several_offer_only_scope_showrooms(): void
    {
        $own = Showroom::factory()->create();
        $second = Showroom::factory()->create();
        $foreign = Showroom::factory()->create();
        $ownSource = $this->makeSource($own);
        $secondSource = $this->makeSource($second);
        $this->makeSource($own, ['name' => 'Другой салон · служебный'])->forceFill(['transfer_from_showroom_id' => $second->id])->save();
        $single = $this->worker([$own]);
        $multi = $this->worker([$own, $second], 'Два салона');

        $this->actingAs($single)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.create', true)
                ->where('createForm.showrooms', [['id' => $own->id, 'name' => $own->name, 'active' => true]])
                ->where('createForm.choices.sources', fn ($list) => collect($list)->pluck('id')->all() === [$ownSource->id]));
        $this->createOrder($single, $ownSource, ['showroom_id' => null]);
        $this->assertSame($own->id, Order::query()->latest('id')->value('showroom_id'));
        $this->actingAs($single)->post(route('orders.store'), $this->createPayload($secondSource, ['showroom_id' => null]))->assertSessionHasErrors('source_id');

        $this->actingAs($multi)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('createForm.showrooms', fn ($list) => collect($list)->pluck('id')->sort()->values()->all() === collect([$own->id, $second->id])->sort()->values()->all())
                ->where('createForm.choices', null));
        $this->actingAs($multi)->getJson(route('orders.create-sources', ['showroom_id' => $own->id]))
            ->assertOk()->assertJsonPath('sources.*.id', [$ownSource->id]);
        $this->actingAs($multi)->getJson(route('orders.create-sources', ['showroom_id' => $foreign->id]))->assertNotFound();
        $this->actingAs($single)->getJson(route('orders.create-sources', ['showroom_id' => $second->id]))->assertNotFound();

        $this->actingAs($multi)->post(route('orders.store'), $this->createPayload($ownSource, ['showroom_id' => $second->id]))->assertSessionHasErrors('source_id');
        $this->actingAs($single)->post(route('orders.store'), $this->createPayload($secondSource, ['showroom_id' => $own->id]))->assertSessionHasErrors('source_id');
        $this->actingAs($single)->post(route('orders.store'), $this->createPayload($secondSource, ['showroom_id' => $second->id]))->assertSessionHasErrors('showroom_id');
        $this->actingAs($multi)->post(route('orders.store'), $this->createPayload($ownSource, ['showroom_id' => null]))->assertSessionHasErrors('showroom_id');
        $this->assertSame(1, Order::query()->count());

        $this->createOrder($multi, $secondSource, ['showroom_id' => $second->id]);
        $this->assertSame($second->id, Order::query()->latest('id')->value('showroom_id'));

        $nowhere = $this->worker([], 'Без салонов');
        $this->actingAs($nowhere)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.create', false)->where('createForm', null));
    }

    public function test_internal_transfer_source_cannot_be_chosen_manually(): void
    {
        $own = Showroom::factory()->create();
        $sender = Showroom::factory()->create();
        $internal = $this->makeSource($own);
        $internal->forceFill(['transfer_from_showroom_id' => $sender->id])->save();
        $employee = $this->worker([$own]);

        $this->actingAs($employee)->post(route('orders.store'), $this->createPayload($internal))->assertSessionHasErrors('source_id');
        $this->actingAs($employee)->post(route('orders.store'), $this->createPayload($internal, ['showroom_id' => $own->id]))->assertSessionHasErrors('source_id');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_numbers_of_two_clients_are_a_conflict_that_reveals_nothing(): void
    {
        $own = Showroom::factory()->create();
        $foreign = Showroom::factory()->create();
        $admin = $this->administrator();
        $this->createOrder($admin, $this->makeSource($foreign), ['contact_name' => 'Чужой Клиент', 'phones' => ['+7 911 111-11-11']]);
        $this->createOrder($admin, $this->makeSource($own), ['contact_name' => 'Свой', 'phones' => ['+7 922 222-22-22']]);
        $source = $this->makeSource($own);
        $employee = $this->worker([$own]);

        $response = $this->actingAs($employee)->post(route('orders.store'), $this->createPayload($source, ['phones' => ['+7 922 222-22-22', '+7 911 111-11-11']]));

        $response->assertSessionHasErrors(['phones' => OrderManager::PHONE_CONFLICT]);
        $this->assertSame(2, Client::query()->count());
        $this->assertSame(2, Order::query()->count());

        $order = Order::query()->where('showroom_id', $own->id)->firstOrFail();
        $this->actingAs($employee)->put(route('orders.update', $order->id), $this->updatePayloadFor($order, ['phones' => ['+7 922 222-22-22', '+7 911 111-11-11']]))
            ->assertSessionHasErrors(['phones' => OrderManager::PHONE_TAKEN]);
        $this->assertSame(['79111111111'], Client::query()->where('name', 'Чужой Клиент')->firstOrFail()->phones()->pluck('phone')->all());
    }

    public function test_scope_hides_foreign_orders_from_list_search_and_direct_urls(): void
    {
        $own = Showroom::factory()->create();
        $foreign = Showroom::factory()->create();
        $admin = $this->administrator();
        $mine = $this->createOrder($admin, $this->makeSource($own), ['contact_name' => 'Свой', 'phones' => ['+7 933 333-33-33']]);
        $theirs = $this->createOrder($admin, $this->makeSource($foreign), ['contact_name' => 'Чужой', 'phones' => ['+7 944 444-44-44']]);
        $employee = $this->worker([$own]);

        $this->actingAs($employee)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page->component('orders/index')
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $mine->id)
                ->where('orders.data.0.phone', '+7 933 333-33-33'));

        $this->actingAs($employee)->get(route('orders.index', ['search' => '+7 944 444-44-44']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));
        $this->actingAs($employee)->get(route('orders.index', ['search' => '3333']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));
        $this->actingAs($employee)->get(route('orders.index', ['search' => '89333333333']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));
        $this->actingAs($employee)->get(route('orders.index', ['search' => '(933) 333-33-33']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));
        $this->actingAs($employee)->get(route('orders.index', ['search' => '9443444444']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));

        $this->actingAs($employee)->get(route('orders.show', $theirs->id))->assertNotFound();
        $this->actingAs($employee)->put(route('orders.update', $theirs->id), $this->updatePayloadFor($theirs))->assertNotFound();
        $this->actingAs($employee)->post(route('orders.comments.store', $theirs->id), ['body' => 'Текст'])->assertNotFound();
        $this->assertSame(0, AuditLog::query()->where('event', 'order.viewed')->count());
    }

    public function test_created_date_filter_takes_moscow_days_and_a_from_to_range(): void
    {
        $showroom = Showroom::factory()->create();
        $admin = $this->administrator();
        $source = $this->makeSource($showroom);
        $received = [
            'late' => '2026-10-20 20:59:59',
            'first' => '2026-10-20 21:00:00',
            'last' => '2026-10-22 20:59:00',
            'next' => '2026-10-22 21:00:00',
        ];
        $ids = [];

        foreach ($received as $key => $at) {
            $order = $this->createOrder($admin, $source, ['contact_name' => $key, 'phones' => ['+7 966 000-00-0'.count($ids)]]);
            Order::query()->where('id', $order->id)->update(['received_at' => $at]);
            $ids[$key] = $order->id;
        }

        $found = function (string $operator, array $values) use ($admin): array {
            $ids = [];
            $this->actingAs($admin)
                ->get(route('orders.index', ['filter' => [['field' => 'received_at', 'operator' => $operator, 'values' => $values]]]))
                ->assertInertia(function (Assert $page) use (&$ids) {
                    $ids = collect($page->toArray()['props']['orders']['data'])->pluck('id')->sort()->values()->all();
                });

            return $ids;
        };

        $this->assertSame([$ids['first'], $ids['last']], $found('between', ['2026-10-22', '21.10.2026']));
        $this->assertSame([$ids['late']], $found('is', ['2026-10-20']));
        $this->assertSame([$ids['next']], $found('on_or_after', ['2026-10-23']));
        $this->assertSame([$ids['late'], $ids['first'], $ids['last']], $found('on_or_before', ['2026-10-22']));
        $this->assertCount(4, $found('between', ['2026-10-22']));
        $this->assertCount(4, $found('between', ['2026-10-22', '2026-02-30']));
    }

    public function test_list_tabs_count_within_scope_and_sorting_is_whitelisted(): void
    {
        $own = Showroom::factory()->create();
        $foreign = Showroom::factory()->create();
        $admin = $this->administrator();
        $beta = $this->makeSource($own, ['name' => 'Бета']);
        $alpha = $this->makeSource($own, ['name' => 'Альфа']);
        $first = $this->createOrder($admin, $beta, ['contact_name' => 'Анна', 'phones' => ['+7 955 555-55-51']]);
        $this->createOrder($admin, $alpha, ['contact_name' => 'Борис', 'phones' => ['+7 955 555-55-51']]);
        $third = $this->createOrder($admin, $alpha, ['contact_name' => 'Вера', 'phones' => ['+7 955 555-55-52']]);
        $this->createOrder($admin, $this->makeSource($foreign), ['contact_name' => 'Чужая', 'phones' => ['+7 955 555-55-53']]);
        $inWork = OrderStatus::query()->where('name', 'В работе')->firstOrFail();
        $approve = OrderStatus::query()->where('name', 'Одобрить')->firstOrFail();
        $initial = OrderStatus::query()->where('is_initial', true)->firstOrFail();
        Order::query()->where('id', $third->id)->update(['order_status_id' => $inWork->id]);
        $employee = $this->worker([$own]);

        $counts = fn (array $tabs) => collect($tabs)->mapWithKeys(fn ($tab) => [$tab['key'] => $tab['count']])->all();

        $this->actingAs($employee)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 3)
                ->where('filters.tab', 'all')
                ->where('filters.sort', 'received_at')
                ->where('tabs', function ($tabs) use ($counts, $initial, $inWork, $approve) {
                    $byKey = $counts(collect($tabs)->all());

                    return array_key_first($byKey) === 'all' && ! array_key_exists('repeats', $byKey)
                        && $byKey['all'] === 3
                        && $byKey[(string) $initial->id] === 2 && $byKey[(string) $inWork->id] === 1
                        && ! array_key_exists((string) $approve->id, $byKey);
                }));

        $this->actingAs($employee)->get(route('orders.index', ['tab' => 'repeats']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 3)->where('filters.tab', 'all'));
        $this->actingAs($employee)->get(route('orders.index', ['tab' => (string) $inWork->id]))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.id', $third->id));
        $this->actingAs($employee)->get(route('orders.index', ['tab' => (string) $approve->id]))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 3)->where('filters.tab', 'all'));

        $this->actingAs($employee)->get(route('orders.index', ['search' => 'Анна']))
            ->assertInertia(fn (Assert $page) => $page->where('tabs', fn ($tabs) => $counts(collect($tabs)->all())['all'] === 1));

        foreach (['Первый', 'Второй', 'Третий', 'Четвёртый'] as $body) {
            OrderComment::query()->create(['order_id' => $first->id, 'author_id' => $admin->id, 'author_name' => 'Автор', 'body' => $body]);
        }
        $this->actingAs($employee)->get(route('orders.index', ['search' => 'Анна']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.data.0.comments_count', 4)
                ->has('orders.data.0.comments', 3)
                ->where('orders.data.0.comments.0.body', 'Четвёртый')
                ->where('orders.data.0.comments.2.body', 'Второй'));

        $this->actingAs($employee)->get(route('orders.index', ['sort' => 'source', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page->where('orders.data.0.source', 'Альфа')->where('orders.data.2.id', $first->id));
        $this->actingAs($employee)->get(route('orders.index', ['sort' => 'contact_name', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page->where('orders.data.0.id', $third->id)->where('filters.direction', 'desc'));
        $this->actingAs($employee)->get(route('orders.index', ['sort' => 'status', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page->where('orders.data.2.id', $third->id));
        $this->actingAs($employee)->get(route('orders.index', ['sort' => 'password', 'direction' => 'sideways']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 3)->where('filters.sort', 'received_at')->where('filters.direction', 'desc'));

        $defaults = ['view' => 'table', 'filters' => true, 'columns' => null, 'order' => null, 'pinning' => ['start' => [], 'end' => []], 'sort' => null, 'direction' => null];
        $this->actingAs($employee)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page->where('listPreferences', $defaults));

        $saved = [
            'view' => 'cards', 'filters' => false, 'columns' => ['showroom' => true, 'phone' => false],
            'order' => ['phone', 'contact_name'], 'pinning' => ['start' => ['phone'], 'end' => ['actions']],
            'sort' => 'contact_name', 'direction' => 'asc',
        ];
        $this->actingAs($employee)->putJson(route('orders.list-preferences'), $saved)->assertNoContent();
        $this->actingAs($employee)->putJson(route('orders.list-preferences'), [...$saved, 'sort' => 'password', 'columns' => ['phone' => 'yes']])
            ->assertUnprocessable()->assertJsonValidationErrors(['sort', 'columns.phone']);

        $this->actingAs($employee)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('listPreferences', $saved)
                ->where('filters.sort', 'contact_name')
                ->where('filters.direction', 'asc')
                ->where('orders.data.0.id', $first->id));
        $this->actingAs($employee)->get(route('orders.index', ['sort' => 'source', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.sort', 'source'));
        $this->actingAs($this->worker([$own]))->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page->where('listPreferences', $defaults));
    }

    public function test_create_update_and_assign_need_their_own_permissions(): void
    {
        $showroom = Showroom::factory()->create();
        $other = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $order = $this->createOrder($this->administrator(['name' => 'Админ']), $source);
        $viewer = $this->employeeWith('order_viewer', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL], [$showroom]);
        $worker = $this->worker([$showroom]);
        $assigner = $this->employeeWith('order_assigner', [...self::WORKER, P::ORDERS_ASSIGN], [$showroom]);
        $candidate = $this->worker([$showroom], 'Кандидат');
        $stranger = $this->worker([$other], 'Чужой сотрудник');
        $inactive = $this->worker([$showroom], 'Уволенный', ['active' => false]);

        $this->actingAs($viewer)->post(route('orders.store'), $this->createPayload($source))->assertForbidden();
        $this->actingAs($viewer)->put(route('orders.update', $order->id), $this->updatePayloadFor($order))->assertForbidden();

        $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayloadFor($order, ['responsible_id' => $candidate->id]))
            ->assertSessionHasErrors('responsible_id');
        $this->actingAs($worker)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page->where('can.assign', false)->where('options.responsibles', null));

        foreach ([$stranger, $inactive] as $wrong) {
            $this->actingAs($assigner)->put(route('orders.update', $order->id), $this->updatePayloadFor($order, ['responsible_id' => $wrong->id]))
                ->assertSessionHasErrors('responsible_id');
        }

        $this->actingAs($assigner)->put(route('orders.update', $order->id), $this->updatePayloadFor($order, ['responsible_id' => $candidate->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($candidate->id, $order->fresh()->responsible_id);
        $log = AuditLog::query()->where('event', 'order.responsible_changed')->sole();
        $this->assertSame(['Админ', 'Кандидат'], $log->changes['responsible']);
        $this->assertSame($assigner->id, $log->actor_id);
        $this->assertSame(AuditLog::ACTOR_USER, $log->actor_kind);
    }

    public function test_responsible_on_creation_is_the_creator_or_chosen_by_the_assign_scope(): void
    {
        $showroom = Showroom::factory()->create();
        $other = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $operator = $this->worker([$showroom], 'Оператор');
        $local = $this->employeeWith('order_assign_local', [...self::WORKER, P::ORDERS_ASSIGN], [$showroom]);
        $chief = $this->employeeWith('order_assign_any', [...self::WORKER, P::ORDERS_ASSIGN_ANY], [$showroom]);
        $colleague = $this->worker([$showroom], 'Коллега');
        $stranger = $this->worker([$other], 'Из другого салона');

        $this->assertSame($operator->id, $this->createOrder($operator, $source)->responsible_id);
        $this->actingAs($operator)->post(route('orders.store'), $this->createPayload($source, ['responsible_id' => $colleague->id]))
            ->assertSessionHasErrors('responsible_id');

        $this->assertSame($colleague->id, $this->createOrder($local, $source, ['responsible_id' => $colleague->id])->responsible_id);
        $this->actingAs($local)->post(route('orders.store'), $this->createPayload($source, ['responsible_id' => $stranger->id]))
            ->assertSessionHasErrors(['responsible_id' => OrderManager::RESPONSIBLE_INVALID]);
        $this->assertNull($this->createOrder($local, $source, ['responsible_id' => null])->responsible_id);

        $this->assertSame($stranger->id, $this->createOrder($chief, $source, ['responsible_id' => $stranger->id])->responsible_id);

        $this->actingAs($local)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('createForm.responsibles', fn ($list) => collect($list)->pluck('name')->contains('Коллега') && ! collect($list)->pluck('name')->contains('Из другого салона')));
        $this->actingAs($chief)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('createForm.responsibles', fn ($list) => collect($list)->pluck('name')->contains('Из другого салона')));
        $this->actingAs($operator)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page->where('createForm.responsibles', null));
    }

    public function test_phone_names_are_kept(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $operator = $this->worker([$showroom]);

        $order = $this->createOrder($operator, $source, ['phones' => [
            ['number' => '+7 (951) 534-67-83', 'name' => 'не должно сохраниться'],
            ['number' => '+7 (900) 000-00-01', 'name' => ' Жена '],
            ['number' => '', 'name' => 'пустая строка'],
        ]]);

        $this->assertSame(
            [['79515346783', null], ['79000000001', 'Жена']],
            array_map(fn (array $phone) => [$phone['phone'], $phone['name']], $order->contactPhones()),
        );

        $this->actingAs($operator)->put(route('orders.update', $order->id), $this->updatePayloadFor($order, ['phones' => [
            ['number' => '79515346783', 'name' => null],
            ['number' => '79000000001', 'name' => 'Супруга'],
        ]]))->assertSessionHasNoErrors();

        $this->assertSame('Супруга', $order->additionalPhones()->where('position', 1)->value('name'));
        $this->assertSame(
            ['+7 951 534-67-83, +7 900 000-00-01 (Жена)', '+7 951 534-67-83, +7 900 000-00-01 (Супруга)'],
            AuditLog::query()->where('event', 'order.updated')->sole()->changes['phones'],
        );
    }

    public function test_creation_accepts_status_dates_reason_and_first_comment(): void
    {
        $showroom = Showroom::factory()->create(['timezone' => 'Europe/Moscow']);
        $other = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $worker = $this->worker([$showroom]);
        $silent = $this->employeeWith('order_silent', [P::ORDERS_VIEW, P::ORDERS_CREATE], [$showroom]);
        $callback = OrderStatus::query()->where('requires_callback_at', true)->firstOrFail();
        $trash = OrderStatus::query()->where('required_reason', 'trash')->firstOrFail();
        $system = OrderStatus::query()->whereNotNull('purpose')->firstOrFail();
        $foreignReason = $this->trashReason('Только для другого салона', ['all_showrooms' => false], [$other]);
        $reason = $this->trashReason('Дубль');

        $this->actingAs($worker)->post(route('orders.store'), $this->createPayload($source, ['order_status_id' => $callback->id]))->assertSessionHasErrors('callback_at');
        $this->actingAs($worker)->post(route('orders.store'), $this->createPayload($source, ['order_status_id' => $system->id]))->assertSessionHasErrors('order_status_id');
        $this->actingAs($worker)->post(route('orders.store'), $this->createPayload($source, ['order_status_id' => $trash->id, 'reason_id' => $foreignReason->id]))->assertSessionHasErrors('reason_id');
        $this->actingAs($silent)->post(route('orders.store'), $this->createPayload($source, ['comment' => 'Текст']))->assertSessionHasErrors('comment');
        $this->assertSame(0, Order::query()->count());

        $order = $this->createOrder($worker, $source, [
            'order_status_id' => $callback->id,
            'callback_at' => '2026-10-25T10:30',
            'arrival_at' => '2026-10-26T12:00',
            'comment' => ' Просил перезвонить утром ',
        ]);
        $this->assertSame($callback->id, $order->order_status_id);
        $this->assertSame('2026-10-25 07:30', $order->callback_at->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-26 12:00', $order->arrival_at->setTimezone('Europe/Moscow')->format('Y-m-d H:i'));
        $this->assertSame(['Просил перезвонить утром'], $order->comments()->pluck('body')->all());

        $trashed = $this->createOrder($worker, $source, ['order_status_id' => $trash->id, 'reason_id' => $reason->id]);
        $this->assertSame($reason->id, $trashed->order_trash_reason_id);
        $this->assertSame(0, $trashed->comments()->count());
    }

    public function test_client_name_is_optional_on_creation_and_editing(): void
    {
        $showroom = Showroom::factory()->create();
        $worker = $this->worker([$showroom]);

        $order = $this->createOrder($worker, $this->makeSource($showroom), ['contact_name' => '']);
        $this->assertNull($order->contact_name);
        $this->assertNull($order->client->name);

        $named = $this->createOrder($worker, $this->makeSource($showroom), ['contact_name' => 'Иван']);
        $this->actingAs($worker)->put(route('orders.update', $named->id), $this->updatePayloadFor($named, ['contact_name' => null]))
            ->assertSessionHasNoErrors();
        $this->assertNull($named->fresh()->contact_name);
    }

    public function test_status_requirements_and_reason_availability_are_enforced(): void
    {
        $showroom = Showroom::factory()->create();
        $other = Showroom::factory()->create();
        $order = $this->createOrder($this->administrator(), $this->makeSource($showroom));
        $worker = $this->worker([$showroom]);
        $callback = OrderStatus::query()->where('requires_callback_at', true)->firstOrFail();
        $trash = OrderStatus::query()->where('required_reason', 'trash')->firstOrFail();
        $plain = OrderStatus::query()->where('is_initial', false)->where('active', true)->where('requires_callback_at', false)->where('requires_arrival_at', false)->whereNull('required_reason')->firstOrFail();
        $foreignReason = $this->trashReason('Только для другого салона', ['all_showrooms' => false], [$other]);
        $disabledReason = $this->trashReason('Отключённая', ['active' => false]);
        $commented = $this->trashReason('С пояснением', ['comment_required' => true]);

        $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayloadFor($order, ['order_status_id' => $callback->id]))
            ->assertSessionHasErrors('callback_at');
        $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayloadFor($order, ['order_status_id' => $callback->id, 'callback_at' => '2026-10-25T10:30']))
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-10-25 10:30', $order->fresh()->callback_at->setTimezone($showroom->timezone)->format('Y-m-d H:i'));
        $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayloadFor($order, ['callback_at' => null]))
            ->assertSessionHasErrors('callback_at');

        foreach ([null, $foreignReason->id, $disabledReason->id] as $reason) {
            $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayloadFor($order, ['order_status_id' => $trash->id, 'reason_id' => $reason]))
                ->assertSessionHasErrors('reason_id');
        }

        $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayloadFor($order, ['order_status_id' => $trash->id, 'reason_id' => $commented->id]))
            ->assertSessionHasErrors('reason_comment');
        $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayloadFor($order, ['order_status_id' => $trash->id, 'reason_id' => $commented->id, 'reason_comment' => 'Купил в другом месте']))
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame($commented->id, $order->order_trash_reason_id);
        $this->assertSame('2026-10-25 10:30', $order->callback_at->setTimezone($showroom->timezone)->format('Y-m-d H:i'));

        $commented->forceFill(['active' => false])->save();
        $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayloadFor($order, ['car_interest' => 'Седан']))
            ->assertSessionHasNoErrors();
        $this->assertSame($commented->id, $order->fresh()->order_trash_reason_id);

        $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayloadFor($order, ['order_status_id' => $plain->id]))
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertNull($order->order_trash_reason_id);
        $this->assertNull($order->reason_comment);
        $this->assertNotNull($order->callback_at);

        $statusLog = AuditLog::query()->where('event', 'order.status_changed')->latest('id')->firstOrFail();
        $this->assertSame([$trash->name, $plain->name], $statusLog->changes['status']);
        $updateLog = AuditLog::query()->where('event', 'order.updated')->latest('id')->firstOrFail();
        $this->assertSame(['С пояснением', null], $updateLog->changes['reason']);
    }

    public function test_stale_lock_version_is_a_conflict(): void
    {
        $showroom = Showroom::factory()->create();
        $order = $this->createOrder($this->administrator(), $this->makeSource($showroom));
        $worker = $this->worker([$showroom]);
        $stale = $this->updatePayloadFor($order, ['contact_name' => 'Второй']);

        $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayloadFor($order, ['contact_name' => 'Первый']))->assertSessionHasNoErrors();
        $this->actingAs($worker)->put(route('orders.update', $order->id), $stale)->assertSessionHasErrors('lock_version');

        $this->assertSame('Первый', $order->fresh()->contact_name);
        $this->assertSame(1, $order->fresh()->lock_version);
    }

    public function test_history_and_previous_orders_need_separate_permissions(): void
    {
        $own = Showroom::factory()->create();
        $second = Showroom::factory()->create();
        $hidden = Showroom::factory()->create();
        $admin = $this->administrator();
        $phone = ['+7 955 555-55-55'];
        $earlier = $this->createOrder($admin, $this->makeSource($own), ['phones' => $phone]);
        $inSecond = $this->createOrder($admin, $this->makeSource($second), ['phones' => $phone]);
        $this->createOrder($admin, $this->makeSource($hidden), ['phones' => $phone]);
        $order = $this->createOrder($admin, $this->makeSource($own), ['phones' => $phone]);

        $plain = $this->worker([$own, $second]);
        $this->actingAs($plain)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page->component('orders/show')
                ->where('order.repeat_number', 1)
                ->missing('previous')
                ->missing('history')
                ->missing('crossShowroom')
                ->reload(fn (Assert $reload) => $reload->missing('history'), only: 'history'));

        $historian = $this->employeeWith('order_historian', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_HISTORY], [$own]);
        $this->actingAs($historian)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page->missing('previous')->missing('history')
                ->reloadOnly('history', fn (Assert $reload) => $reload
                    ->has('history.data', 3)
                    ->where('history.data.0.event', 'order.viewed')
                    ->where('history.data.1.event', 'order.viewed')
                    ->where('history.data.2.event', 'order.created')));

        $previous = $this->employeeWith('order_previous', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_PREVIOUS], [$own, $second]);
        $this->actingAs($previous)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page->has('previous', 1)
                ->where('previous.0.id', $earlier->id)
                ->missing('crossShowroom')
                ->missing('history'));

        $this->actingAs($plain)->getJson(route('orders.previous', $order->id))->assertForbidden();
        $this->actingAs($previous)->getJson(route('orders.previous', $order->id))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $earlier->id);
        $outsider = $this->employeeWith('order_previous_outsider', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_PREVIOUS], [$second]);
        $this->actingAs($outsider)->getJson(route('orders.previous', $order->id))->assertNotFound();
        $this->actingAs($previous)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.data.0.id', $order->id)
                ->where('orders.data.0.can.previous', true)
                ->has('orders.data.0.callback_at')
                ->has('orders.data.0.arrival_at')
                ->has('orders.data.0.updated_at')
                ->has('orders.data.0.source_kind')
                ->where('orders.data.0.source_icon', null)
                ->where('orders.data.0.channel_icon', null)
                ->where('orders.data.0.channel_icon_color', 'gray'));

        $cross = $this->employeeWith('order_cross', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_PREVIOUS, P::ORDERS_CROSS_SHOWROOM], [$own, $second]);
        $this->actingAs($cross)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page->missing('crossShowroom')
                ->reloadOnly('crossShowroom', fn (Assert $reload) => $reload
                    ->has('crossShowroom', 1)
                    ->where('crossShowroom.0.id', $inSecond->id)));

        $crossWithoutPrevious = $this->employeeWith('order_cross_only', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_CROSS_SHOWROOM], [$own, $second]);
        $this->actingAs($crossWithoutPrevious)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page->where('can.crossShowroom', false)->missing('crossShowroom'));

        $this->assertSame(1, $order->fresh()->repeat_number);
    }

    public function test_opening_the_card_is_audited_once_and_never_touches_updated_at(): void
    {
        $showroom = Showroom::factory()->create();
        $order = $this->createOrder($this->administrator(), $this->makeSource($showroom));
        $updatedAt = $order->fresh()->updated_at->toDateTimeString();
        $worker = $this->employeeWith('order_full', [...self::WORKER, P::ORDERS_HISTORY], [$showroom]);
        $this->travel(5)->minutes();

        $this->actingAs($worker)->get(route('orders.show', $order->id), ['Purpose' => 'prefetch'])->assertOk();
        $this->assertSame(0, AuditLog::query()->where('event', 'order.viewed')->count());

        $this->actingAs($worker)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly('history', fn (Assert $reload) => $reload->has('history.data'))
                ->reloadOnly('comments', fn (Assert $reload) => $reload->has('comments.data', 0)));

        $view = AuditLog::query()->where('event', 'order.viewed')->sole();
        $this->assertSame($worker->id, $view->actor_id);
        $this->assertSame([$showroom->id], $view->showrooms()->pluck('showrooms.id')->all());

        $this->actingAs($worker)->post(route('orders.comments.store', $order->id), ['body' => 'Клиент просил перезвонить вечером'])->assertSessionHasNoErrors();

        $this->assertSame(1, AuditLog::query()->where('event', 'order.viewed')->count());
        $this->assertSame($updatedAt, $order->fresh()->updated_at->toDateTimeString());
    }

    public function test_comments_are_added_by_permission_and_audited_without_text(): void
    {
        $showroom = Showroom::factory()->create();
        $order = $this->createOrder($this->administrator(), $this->makeSource($showroom));
        $viewer = $this->employeeWith('order_viewer', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL], [$showroom]);
        $worker = $this->worker([$showroom], 'Оператор');

        $this->actingAs($viewer)->post(route('orders.comments.store', $order->id), ['body' => 'Текст'])->assertForbidden();
        $this->actingAs($worker)->post(route('orders.comments.store', $order->id), ['body' => '   '])->assertSessionHasErrors('body');
        $this->actingAs($worker)->post(route('orders.comments.store', $order->id), ['body' => 'Секретная подробность разговора'])->assertSessionHasNoErrors();

        $comment = OrderComment::query()->sole();
        $this->assertSame('Оператор', $comment->author_name);
        $this->assertSame($worker->id, $comment->author_id);

        $log = AuditLog::query()->where('event', 'order.comment_added')->sole();
        $this->assertSame(['comment_id' => $comment->id], $log->changes);
        $this->assertStringNotContainsString('Секретная', json_encode($log->toArray(), JSON_UNESCAPED_UNICODE));

        $this->actingAs($viewer)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page->missing('comments')
                ->reloadOnly('comments', fn (Assert $reload) => $reload
                    ->has('comments.data', 1)
                    ->where('comments.data.0.body', 'Секретная подробность разговора')));
    }

    public function test_global_audit_hides_order_entries_without_history_permission(): void
    {
        $showroom = Showroom::factory()->create();
        $this->createOrder($this->administrator(), $this->makeSource($showroom));
        $auditor = $this->employeeWith('auditor', [P::AUDIT_VIEW], [], ['has_global_access' => true]);

        $this->actingAs($auditor)->get(route('audit.index'))
            ->assertInertia(fn (Assert $page) => $page->where('entries.data', fn ($entries) => collect($entries)->doesntContain('event', 'order.created')));
    }

    /**
     * @param  list<Showroom>  $showrooms
     */
    private function worker(array $showrooms, string $name = 'Сотрудник', array $attributes = []): User
    {
        return $this->employeeWith('order_worker', self::WORKER, $showrooms, ['name' => $name, ...$attributes]);
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<Showroom>  $showrooms
     */
    private function employeeWith(string $role, array $permissions, array $showrooms, array $attributes = []): User
    {
        Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web'], ['label' => $role])->syncPermissions($permissions);

        return $this->employee($role, $showrooms, $attributes);
    }

    /**
     * @param  list<Showroom>  $showrooms
     */
    private function trashReason(string $name, array $attributes = [], array $showrooms = []): OrderTrashReason
    {
        $reason = new OrderTrashReason(['code' => 'reason-'.uniqid(), 'name' => $name, ...array_diff_key($attributes, ['active' => true])]);
        $reason->active = $attributes['active'] ?? true;
        $reason->save();
        $reason->showrooms()->sync(collect($showrooms)->pluck('id'));

        return $reason;
    }

    private function createOrder(User $actor, Source $source, array $overrides = []): Order
    {
        $this->actingAs($actor)->post(route('orders.store'), $this->createPayload($source, $overrides))->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(Source $source, array $overrides = []): array
    {
        return $this->withPhoneRows([
            'showroom_id' => $source->showroom_id,
            'source_id' => $source->id,
            'contact_channel_id' => $source->channels()->orderBy('contact_channels.id')->value('contact_channels.id'),
            'contact_name' => 'Клиент',
            'phones' => [sprintf('+7 999 000-%02d-%02d', intdiv(++$this->phoneSequence, 100), $this->phoneSequence % 100)],
            'car_interest' => null,
            ...$overrides,
        ]);
    }

    /**
     * Tests list plain numbers; the form sends `phones[i][number|name]`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withPhoneRows(array $payload): array
    {
        $payload['phones'] = array_map(fn (mixed $phone) => is_string($phone) ? ['number' => $phone, 'name' => null] : $phone, $payload['phones']);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayloadFor(Order $order, array $overrides = []): array
    {
        $order->refresh();
        $timezone = $order->showroom->timezone;

        return $this->withPhoneRows([
            'contact_channel_id' => $order->contact_channel_id,
            'contact_name' => $order->contact_name,
            'phones' => array_map(fn (array $phone) => ['number' => $phone['phone'], 'name' => $phone['name']], $order->contactPhones()),
            'car_interest' => $order->car_interest,
            'order_status_id' => $order->order_status_id,
            'callback_at' => $order->callback_at?->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'arrival_at' => $order->arrival_at?->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'reason_id' => $order->order_trash_reason_id ?? $order->order_drop_reason_id,
            'reason_comment' => $order->reason_comment,
            'lock_version' => $order->lock_version,
            ...$overrides,
        ]);
    }
}
