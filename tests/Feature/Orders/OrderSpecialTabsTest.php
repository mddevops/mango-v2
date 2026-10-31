<?php

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\Call;
use App\Models\ContactChannel;
use App\Models\IntegrationConnection;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Showroom;
use App\Models\User;
use App\Services\OrderManager;
use App\Support\PermissionCatalog as P;
use Carbon\CarbonImmutable;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Mango\Concerns\BuildsMangoCalls;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

/**
 * Computed tabs of the orders list: «Срочные», «Просроченные», «Пропущенные», and the
 * explicit handling of missed calls.
 */
class OrderSpecialTabsTest extends TestCase
{
    use BuildsMangoCalls;
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    private User $admin;

    private Showroom $showroom;

    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
        $this->admin = $this->administrator();
        $this->showroom = Showroom::factory()->create(['timezone' => 'Europe/Moscow']);
        config(['inertia.ssr.enabled' => false]);
    }

    public function test_urgent_and_overdue_follow_the_callback_moment_and_the_status(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'Europe/Moscow'));
        $now = CarbonImmutable::now();
        $callback = OrderStatus::query()->where('requires_callback_at', true)->firstOrFail();
        $other = OrderStatus::query()->where('requires_callback_at', false)->where('is_initial', true)->firstOrFail();

        $atNow = $this->order(attributes: ['order_status_id' => $callback->id, 'callback_at' => $now]);
        $inHour = $this->order(attributes: ['order_status_id' => $callback->id, 'callback_at' => $now->addMinutes(60)]);
        $later = $this->order(attributes: ['order_status_id' => $callback->id, 'callback_at' => $now->addMinutes(60)->addSecond()]);
        $justPassed = $this->order(attributes: ['order_status_id' => $callback->id, 'callback_at' => $now->subSecond()]);
        $yesterday = $this->order(attributes: ['order_status_id' => $callback->id, 'callback_at' => $now->subDay()->subHours(3)]);
        $otherStatus = $this->order(attributes: ['order_status_id' => $other->id, 'callback_at' => $now->subHours(5)]);
        $this->order(attributes: ['order_status_id' => $callback->id, 'callback_at' => null]);

        [$urgent, $counts] = $this->tab($this->admin, 'urgent');
        $this->assertSame([$atNow->id, $inHour->id], $urgent);
        $this->assertSame(['urgent' => 2, 'overdue' => 2, 'missed' => 0], $counts);
        $this->assertNotContains($later->id, $urgent);

        [$overdue] = $this->tab($this->admin, 'overdue');
        $this->assertSame([$yesterday->id, $justPassed->id], $overdue);
        $this->assertNotContains($otherStatus->id, $overdue);

        // The counters do not depend on the open tab.
        [, $countsOnAll] = $this->tab($this->admin, 'all');
        $this->assertSame($counts, $countsOnAll);

        $this->actingAs($this->admin)->get(route('orders.index', ['tab' => 'urgent']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.tab', 'urgent')
                ->where('filters.sort', 'callback_at')
                ->where('filters.direction', 'asc')
                ->where('orders.data.0.callback_in', 0)
                ->where('orders.data.1.callback_in', 3600));

        // A callback moved to the future or a status without callback leaves «Просроченные».
        Order::query()->whereKey($yesterday->id)->update(['callback_at' => $now->addMinutes(30)]);
        Order::query()->whereKey($justPassed->id)->update(['order_status_id' => $other->id]);
        $this->assertSame([], $this->tab($this->admin, 'overdue')[0]);
        $this->assertSame([$atNow->id, $yesterday->id, $inHour->id], $this->tab($this->admin, 'urgent')[0]);

        // Time alone moves an urgent order to «Просроченные»; the status stays.
        $this->travel(61)->seconds();
        $this->assertSame([$atNow->id], $this->tab($this->admin, 'overdue')[0]);
        $this->assertSame($callback->id, $atNow->fresh()->order_status_id);
    }

    public function test_missed_calls_of_an_old_order_are_one_row_and_close_only_by_an_explicit_action(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00', 'Europe/Moscow'));
        $connection = $this->mangoConnection($this->showroom);
        $this->bindLine($connection, $this->makeSource($this->showroom), self::LINE);
        $operator = $this->mangoEmployee('Оператор', [$this->showroom]);
        $old = $this->order($operator->id, phone: self::CLIENT);

        $this->travelTo(CarbonImmutable::parse('2026-10-19 12:30', 'Europe/Moscow'));
        $this->mangoEvent($connection, 'summary', $this->summary('entry-1', null))->assertOk();
        $this->mangoEvent($connection, 'summary', $this->summary('entry-2', null, 600))->assertOk();
        // An answered call and a call without Mango's result yet are not missed ones.
        $this->mangoEvent($connection, 'summary', $this->summary('entry-3', '123', 900))->assertOk();
        foreach ($this->routedCall('entry-4', '124', 1200, false) as $event) {
            $this->mangoEvent($connection, 'call', $event)->assertOk();
        }

        $this->assertSame(1, Order::query()->count());
        [$c1, $c2] = [$this->callOf('entry-1'), $this->callOf('entry-2')];

        $this->actingAs($operator)->get(route('orders.index', ['tab' => 'missed']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $old->id)
                ->where('orders.data.0.missed_count', 2)
                ->where('orders.data.0.last_missed_at', $c2->started_at->toISOString())
                ->where('filters.sort', 'last_missed_at')
                ->where('tabs', fn ($tabs) => collect($tabs)->firstWhere('key', 'missed')['count'] === 1));

        // Opening the card closes nothing; the card lists the missed calls.
        $this->actingAs($operator)->get(route('orders.show', $old->id))
            ->assertInertia(fn (Assert $page) => $page->has('missedCalls', 2)->where('missedCalls.0.id', $c2->id));
        $this->assertSame(2, Call::query()->unhandledMissed()->count());

        // «Обработать все» closes only the calls the employee saw: one that came meanwhile stays.
        $this->mangoEvent($connection, 'summary', $this->summary('entry-5', null, 1800))->assertOk();
        $this->actingAs($operator)->post(route('orders.missed-calls.handle', $old->id), ['call_ids' => [$c1->id, $c2->id]])->assertRedirect();

        $this->assertSame([$this->callOf('entry-5')->id], Call::query()->unhandledMissed()->pluck('id')->all());
        $this->assertSame($operator->id, $c1->fresh()->missed_handled_by_id);
        $this->assertSame(Call::MISSED, $c1->fresh()->result);
        $audit = AuditLog::query()->where('event', 'order.missed_calls_handled')->sole();
        $this->assertSame([$old->id, $operator->id, [$c1->id, $c2->id]], [$audit->subject_id, $audit->actor_id, $audit->changes['calls']]);
        $this->assertSame([$old->id], $this->tab($operator, 'missed')[0]);

        // Repeating the action changes nothing and writes no audit.
        $handledAt = $c1->fresh()->missed_handled_at;
        $this->actingAs($operator)->post(route('orders.missed-calls.handle', $old->id), ['call_ids' => [$c1->id, $c2->id]])->assertRedirect();
        $this->assertSame(1, AuditLog::query()->where('event', 'order.missed_calls_handled')->count());
        $this->assertEquals($handledAt, $c1->fresh()->missed_handled_at);

        // A repeated or late Mango event of the call keeps the mark.
        $this->mangoEvent($connection, 'summary', $this->summary('entry-1', null))->assertOk();
        $this->mangoEvent($connection, 'summary', $this->summary('entry-1', null, 0, ['disconnect_reason' => 1110]))->assertOk();
        $this->assertEquals($handledAt, $c1->fresh()->missed_handled_at);
        $this->assertSame(Call::MISSED, $c1->fresh()->result);

        // One call handled alone; then the order leaves the tab.
        $this->actingAs($operator)->post(route('orders.missed-calls.handle', $old->id), ['call_ids' => [$this->callOf('entry-5')->id]])->assertRedirect();
        $this->assertSame([], $this->tab($operator, 'missed')[0]);
        $this->assertSame(0, $this->tab($operator, 'all')[1]['missed']);
        $this->assertSame('2026-09-01', $old->fresh()->created_at->setTimezone('Europe/Moscow')->toDateString());
    }

    public function test_the_list_counters_and_handling_keep_the_access_scope(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'Europe/Moscow'));
        $connection = $this->mangoConnection($this->showroom);
        $foreignShowroom = Showroom::factory()->create();
        $operator = $this->mangoEmployee('Оператор', [$this->showroom]);
        $colleague = $this->mangoEmployee('Коллега', [$this->showroom]);
        $senior = $this->mangoEmployee('Старший', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_UPDATE]);
        $viewer = $this->mangoEmployee('Только просмотр', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL]);
        $callback = OrderStatus::query()->where('requires_callback_at', true)->firstOrFail();
        $overdue = ['order_status_id' => $callback->id, 'callback_at' => CarbonImmutable::now()->subHour()];

        $own = $this->order($operator->id, attributes: $overdue);
        $foreignOwner = $this->order($colleague->id, attributes: $overdue);
        $unassigned = $this->order(attributes: $overdue);
        $otherShowroom = $this->order(showroom: $foreignShowroom, attributes: [...$overdue, 'responsible_id' => $operator->id]);

        foreach ([$own, $foreignOwner, $unassigned, $otherShowroom] as $order) {
            $this->missedCall($connection, $order);
        }

        foreach (['missed', 'overdue'] as $tab) {
            [$ids, $counts] = $this->tab($operator, $tab);
            $this->assertSame([$own->id], $ids, $tab);
            $this->assertSame(1, $counts[$tab], $tab);

            [$ids, $counts] = $this->tab($senior, $tab);
            $this->assertEqualsCanonicalizing([$own->id, $foreignOwner->id, $unassigned->id], $ids, $tab);
            $this->assertSame(3, $counts[$tab], $tab);
        }

        $foreignCall = Call::query()->where('order_id', $foreignOwner->id)->sole();
        $this->actingAs($operator)->post(route('orders.missed-calls.handle', $foreignOwner->id), ['call_ids' => [$foreignCall->id]])->assertNotFound();
        $this->actingAs($operator)->post(route('orders.missed-calls.handle', $own->id), ['call_ids' => [$foreignCall->id]])->assertRedirect();
        $this->actingAs($viewer)->post(route('orders.missed-calls.handle', $foreignOwner->id), ['call_ids' => [$foreignCall->id]])->assertForbidden();
        $this->assertNull($foreignCall->fresh()->missed_handled_at);
        $this->assertSame(0, AuditLog::query()->where('event', 'order.missed_calls_handled')->count());

        $this->actingAs($senior)->post(route('orders.missed-calls.handle', $foreignOwner->id), ['call_ids' => [$foreignCall->id]])->assertRedirect();
        $this->assertSame($senior->id, $foreignCall->fresh()->missed_handled_by_id);
        $this->assertSame($colleague->id, $foreignOwner->fresh()->responsible_id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(?int $responsibleId = null, ?Showroom $showroom = null, array $attributes = [], ?string $phone = null): Order
    {
        $source = $this->makeSource($showroom ?? $this->showroom, ['channel_ids' => $this->channels('chat', 'direct-call')]);

        $order = app(OrderManager::class)->create($this->admin, [
            'showroom_id' => $source->showroom_id,
            'source_id' => $source->id,
            'contact_channel_id' => ContactChannel::query()->where('code', 'chat')->value('id'),
            'contact_name' => 'Клиент',
            'phones' => [$phone ?? sprintf('7999600%04d', ++$this->phone)],
            'phone_names' => [],
            'car_interest' => null,
            'responsible_id' => $responsibleId,
        ]);

        if ($attributes !== []) {
            Order::query()->whereKey($order->id)->update($attributes);
        }

        return $order->fresh();
    }

    private function missedCall(IntegrationConnection $connection, Order $order): Call
    {
        return Call::query()->create([
            'integration_connection_id' => $connection->id,
            'entry_id' => 'entry-'.$order->id,
            'direction' => 'inbound',
            'direction_final' => true,
            'result' => Call::MISSED,
            'order_id' => $order->id,
            'showroom_id' => $order->showroom_id,
            'started_at' => CarbonImmutable::now()->subMinutes(10),
            'summary_received_at' => CarbonImmutable::now()->subMinutes(9),
            'match_state' => 'linked',
        ]);
    }

    private function callOf(string $entry): Call
    {
        return Call::query()->where('entry_id', $entry)->sole();
    }

    /**
     * The ids of the tab's first page and the counters of the computed tabs.
     *
     * @return array{0: list<int>, 1: array{urgent: int, overdue: int, missed: int}}
     */
    private function tab(User $user, string $tab): array
    {
        $result = [[], []];

        $this->actingAs($user)->get(route('orders.index', ['tab' => $tab, 'per_page' => 100]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$result) {
                $props = $page->toArray()['props'];
                $counts = collect($props['tabs'])->whereIn('key', ['urgent', 'overdue', 'missed'])->pluck('count', 'key')->all();
                $result = [collect($props['orders']['data'])->pluck('id')->all(), $counts];
            });

        return $result;
    }
}
