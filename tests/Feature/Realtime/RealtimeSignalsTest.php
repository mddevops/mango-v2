<?php

namespace Tests\Feature\Realtime;

use App\Events\Realtime\CallChanged;
use App\Events\Realtime\RealtimeSignal;
use App\Models\Call;
use App\Models\ContactChannel;
use App\Models\Order;
use App\Models\Showroom;
use App\Models\User;
use App\Services\OrderManager;
use App\Support\PermissionCatalog as P;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Mango\Concerns\BuildsMangoCalls;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

/**
 * Realtime signals: personal channels, recipients by the current rights at send time,
 * publishing after commit only, and no order data in the payload.
 */
class RealtimeSignalsTest extends TestCase
{
    use BuildsMangoCalls;
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    private User $admin;

    private Showroom $showroom;

    /** @var list<RealtimeSignal> */
    private array $sent = [];

    /** @var list<array{type: string, to: list<int>, payload: array<string, mixed>}> */
    private array $delivered = [];

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

    public function test_the_personal_channel_admits_only_its_active_owner(): void
    {
        $owner = $this->mangoEmployee('Оператор', [$this->showroom]);
        $other = $this->mangoEmployee('Другой', [$this->showroom]);
        $authorize = Broadcast::driver()->getChannels()->get('App.Models.User.{id}');

        $this->assertTrue($authorize($owner, (string) $owner->id));
        $this->assertFalse($authorize($other, (string) $owner->id));

        $owner->forceFill(['active' => false])->save();
        $this->assertFalse($authorize($owner, (string) $owner->id));
    }

    public function test_a_new_order_reaches_only_those_who_may_see_it_and_notifies_its_responsible(): void
    {
        $this->captureSignals();
        $foreign = Showroom::factory()->create();
        $operator = $this->mangoEmployee('Оператор', [$this->showroom]);
        $colleague = $this->mangoEmployee('Коллега', [$this->showroom]);
        $manager = $this->mangoEmployee('Руководитель', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL]);
        $stranger = $this->mangoEmployee('Чужой салон', [$foreign], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL]);
        $disabled = $this->mangoEmployee('Отключён', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL], ['active' => false]);

        $order = $this->order($operator->id);

        $changed = $this->signals('order.updated')->sole();
        $this->assertSame($this->ids($this->admin, $operator, $manager), $this->recipients($changed));
        $this->assertNotContains($colleague->id, $this->recipients($changed));
        $this->assertNotContains($stranger->id, $this->recipients($changed));
        $this->assertNotContains($disabled->id, $this->recipients($changed));
        $this->assertSame(['type', 'id', 'order_id', 'version'], array_keys($changed->broadcastWith()));

        $assigned = $this->signals('order.assigned')->sole();
        $this->assertSame([$operator->id], $this->recipients($assigned));
        $this->assertSame([
            'type' => 'order.assigned',
            'id' => $assigned->id,
            'order_id' => $order->id,
            'version' => $order->lock_version,
            'label' => "Заявка №{$order->id}",
            'url' => "/orders/{$order->id}",
        ], $assigned->broadcastWith());
    }

    public function test_reassignment_notifies_the_new_responsible_and_only_asks_the_previous_one_to_check_access(): void
    {
        $this->captureSignals();
        $previous = $this->mangoEmployee('Прежний', [$this->showroom]);
        $next = $this->mangoEmployee('Новый', [$this->showroom]);
        $manager = $this->mangoEmployee('Руководитель', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL]);
        $order = $this->order($previous->id);
        $this->sent = [];

        $this->reassign($order, $next);

        $this->assertSame([$next->id], $this->recipients($this->signals('order.assigned')->sole()));
        $this->assertNotContains($previous->id, $this->recipients($this->signals('order.updated')->sole()));
        $check = $this->signals('order.access-check')->sole();
        $this->assertSame([$previous->id], $this->recipients($check));
        $this->assertSame(['type' => 'order.access-check', 'id' => $check->id, 'order_id' => $order->id], $check->broadcastWith());

        // Who still sees the order needs no access check: the usual signal reaches him.
        $this->reassign($order, $manager);
        $this->reassign($order, $next);
        $this->assertSame([], $this->recipients($this->signals('order.access-check')->last()));
    }

    public function test_recipients_are_chosen_by_the_rights_at_send_time(): void
    {
        $this->captureSignals();
        $operator = $this->mangoEmployee('Оператор', [$this->showroom]);
        $manager = $this->mangoEmployee('Руководитель', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL]);
        $this->order($operator->id);

        $operator->forceFill(['active' => false])->save();
        $manager->showrooms()->detach();

        $this->assertSame([$this->admin->id], $this->recipients($this->signals('order.updated')->sole()));
        $this->assertSame([], $this->recipients($this->signals('order.assigned')->sole()));
    }

    public function test_signals_are_queued_after_commit_and_a_rolled_back_change_sends_nothing(): void
    {
        $this->captureSignals();

        DB::transaction(function () {
            $this->order();
            $this->assertSame([], $this->sent);
        });
        $this->assertNotEmpty($this->sent);

        $this->sent = [];

        try {
            DB::transaction(function () {
                $this->order();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->sent);
    }

    public function test_a_broadcasting_failure_keeps_the_order(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs) {
            $logs[] = $event->message;
        });
        $this->mock(BroadcastManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('queue')->andThrow(new RuntimeException('reverb is down'));
        });
        $operator = $this->mangoEmployee('Оператор', [$this->showroom]);

        $order = $this->order($operator->id);

        $this->assertTrue(Order::query()->whereKey($order->id)->where('responsible_id', $operator->id)->exists());
        $this->assertContains('Realtime signal was not queued.', $logs);
    }

    public function test_call_and_recording_signals_follow_history_and_recording_rights(): void
    {
        $this->captureSignals();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 12:05', 'Europe/Moscow'));
        $connection = $this->mangoConnection($this->showroom);
        $this->bindLine($connection, $this->makeSource($this->showroom), self::LINE);
        $operator = $this->mangoEmployee('Оператор', [$this->showroom]);
        $other = $this->mangoEmployee('Не ответивший', [$this->showroom]);
        $this->extension($connection, '123', $operator);
        $this->extension($connection, '124', $other);
        $viewer = $this->mangoEmployee('Без истории', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL]);
        $historian = $this->mangoEmployee('История', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_HISTORY]);
        $listener = $this->mangoEmployee('Записи', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_HISTORY, P::ORDERS_RECORDINGS]);

        foreach ($this->routedCall('entry-1') as $event) {
            $this->mangoEvent($connection, 'call', $event)->assertOk();
        }

        $call = Call::query()->where('entry_id', 'entry-1')->sole();
        $this->assertSame($operator->id, Order::query()->findOrFail($call->order_id)->responsible_id);

        $history = $this->recipients($this->signals('call.updated')->last());
        $this->assertSame($this->ids($this->admin, $historian, $listener), $history);
        $this->assertNotContains($viewer->id, $history);
        $this->assertNotContains($operator->id, $history);

        $this->sent = [];
        $this->mangoEvent($connection, 'record/added', ['entry_id' => 'entry-1', 'product_id' => 1, 'user_id' => -1, 'timestamp' => $this->at(60), 'recording_id' => 'rec-1'])->assertOk();

        /** @var CallChanged $recording */
        $recording = $this->signals('call.updated')->sole();
        $this->assertSame('listenRecordings', $recording->ability);
        $this->assertSame($this->ids($this->admin, $listener), $this->recipients($recording));
    }

    public function test_an_unassigned_order_reaches_only_those_who_see_all_orders_of_its_showroom(): void
    {
        $this->captureSignals();
        $operator = $this->mangoEmployee('Оператор', [$this->showroom]);
        $manager = $this->mangoEmployee('Руководитель', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL]);
        $stranger = $this->mangoEmployee('Чужой салон', [Showroom::factory()->create()], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL]);

        $this->order();

        $changed = $this->signals('order.updated')->sole();
        $this->assertSame($this->ids($this->admin, $manager), $this->recipients($changed));
        $this->assertNotContains($operator->id, $this->recipients($changed));
        $this->assertNotContains($stranger->id, $this->recipients($changed));
        $this->assertSame([], $this->signals('order.assigned')->all());
    }

    public function test_the_call_card_goes_only_to_the_addressed_employee_and_follows_the_call(): void
    {
        $this->captureSignals();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 12:05', 'Europe/Moscow'));
        $connection = $this->mangoConnection($this->showroom);
        $this->bindLine($connection, $this->makeSource($this->showroom), self::LINE);
        $operator = $this->mangoEmployee('Оператор', [$this->showroom]);
        $other = $this->mangoEmployee('Другой оператор', [$this->showroom]);
        $manager = $this->mangoEmployee('Старший', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_UPDATE]);
        $senior = $this->mangoEmployee('Старший без номера', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_HISTORY]);
        $this->extension($connection, '123', $operator);
        $this->extension($connection, '124', $other);
        $this->extension($connection, '125', $manager);

        // A new unassigned order: the operator may not open it until he answers and
        // becomes responsible, so before the answer he is told only to close the card.
        foreach ($this->routedCall('entry-1') as $event) {
            $this->mangoEvent($connection, 'call', $event)->assertOk();
        }

        $call = Call::query()->where('entry_id', 'entry-1')->sole();
        $this->assertSame([[$operator->id, 'revoked'], [$operator->id, 'talking'], [$operator->id, 'ended']], $this->cards());
        $this->assertSame(['type', 'id', 'call_id', 'state', 'version'], array_keys($this->delivered[array_key_last($this->delivered)]['payload']));
        $this->assertNotContains($this->admin->id, array_column($this->cards(), 0));
        $this->assertNotContains($senior->id, array_column($this->cards(), 0));

        // A repeat call of the operator's client rings at a senior: he may open the order
        // and sees the card before the answer; answering does not change the responsible.
        $this->delivered = [];

        foreach ($this->routedCall('entry-2', '125', 300) as $event) {
            $this->mangoEvent($connection, 'call', $event)->assertOk();
        }

        $repeat = Call::query()->where('entry_id', 'entry-2')->sole();
        $this->assertSame($call->order_id, $repeat->order_id);
        $this->assertSame([[$manager->id, 'ringing'], [$manager->id, 'talking'], [$manager->id, 'ended']], $this->cards());
        $this->assertSame($operator->id, Order::query()->findOrFail($call->order_id)->responsible_id);

        // An employee who may not open the order gets no card data.
        $this->delivered = [];

        foreach ($this->routedCall('entry-3', '124', 600, false) as $event) {
            $this->mangoEvent($connection, 'call', $event)->assertOk();
        }

        $this->assertSame([[$other->id, 'revoked'], [$other->id, 'revoked']], $this->cards());
    }

    public function test_the_call_card_data_is_given_only_to_an_addressed_employee_who_may_open_the_order(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19 12:05', 'Europe/Moscow'));
        $connection = $this->mangoConnection($this->showroom);
        $source = $this->makeSource($this->showroom, ['name' => 'Сайт салона']);
        $this->bindLine($connection, $source, self::LINE);
        $operator = $this->mangoEmployee('Оператор', [$this->showroom]);
        $other = $this->mangoEmployee('Другой оператор', [$this->showroom]);
        $senior = $this->mangoEmployee('Старший без номера', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL]);
        $stranger = $this->mangoEmployee('Чужой салон', [Showroom::factory()->create()], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL]);
        $this->extension($connection, '123', $operator);
        $this->extension($connection, '124', $other);
        $this->extension($connection, '126', $stranger);

        foreach ($this->routedCall('entry-1') as $event) {
            $this->mangoEvent($connection, 'call', $event)->assertOk();
        }

        $call = Call::query()->where('entry_id', 'entry-1')->sole();
        $order = Order::query()->findOrFail($call->order_id);

        $this->actingAs($operator)->getJson("/calls/{$call->id}/card")
            ->assertOk()
            ->assertExactJson([
                'call_id' => $call->id,
                'state' => 'ended',
                'phone' => Phone::format($call->client_phone),
                'client' => $order->contact_name,
                'status' => $order->status->name,
                'source' => 'Сайт салона',
                'responsible' => 'Оператор',
                'order_id' => $order->id,
                'order_created_at' => $order->created_at->toISOString(),
                'url' => "/orders/{$order->id}",
            ]);

        // Seeing all orders gives no card; nor does an extension of a foreign showroom
        // or of an operator who may not open the order.
        foreach (['entry-2' => '124', 'entry-3' => '126'] as $entry => $extension) {
            foreach ($this->routedCall($entry, $extension, 300, false) as $event) {
                $this->mangoEvent($connection, 'call', $event)->assertOk();
            }
        }

        $this->actingAs($senior)->getJson("/calls/{$call->id}/card")->assertNotFound();
        $this->actingAs($this->admin)->getJson("/calls/{$call->id}/card")->assertNotFound();
        $foreignCall = Call::query()->where('entry_id', 'entry-3')->sole();
        $this->actingAs($stranger)->getJson("/calls/{$foreignCall->id}/card")->assertNotFound();
        $otherCall = Call::query()->where('entry_id', 'entry-2')->sole();
        $this->actingAs($other)->getJson("/calls/{$otherCall->id}/card")->assertNotFound();
        $this->actingAs($operator)->getJson('/calls/999999/card')->assertNotFound();
    }

    public function test_the_browser_is_authorized_only_for_its_own_channel(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
        $operator = $this->mangoEmployee('Оператор', [$this->showroom]);

        $this->actingAs($operator)
            ->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-App.Models.User.{$operator->id}"])
            ->assertOk()
            ->assertJsonStructure(['auth']);
        $this->actingAs($operator)
            ->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-App.Models.User.{$this->admin->id}"])
            ->assertForbidden();
    }

    private function captureSignals(): void
    {
        $this->mock(BroadcastManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('queue')->andReturnUsing(function (RealtimeSignal $signal) {
                $this->sent[] = $signal;
                $this->delivered[] = ['type' => $signal->broadcastAs(), 'to' => $this->recipients($signal), 'payload' => $signal->broadcastWith()];
            });
        });
    }

    /**
     * The call cards as the worker would send them right away: [recipient, state].
     *
     * @return list<array{int, string}>
     */
    private function cards(): array
    {
        return collect($this->delivered)
            ->where('type', 'call.card')
            ->flatMap(fn (array $card) => array_map(fn (int $id) => [$id, $card['payload']['state']], $card['to']))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, RealtimeSignal>
     */
    private function signals(string $type): Collection
    {
        return collect($this->sent)->filter(fn (RealtimeSignal $signal) => $signal->broadcastAs() === $type)->values();
    }

    /**
     * The recipients as the broadcast worker resolves them now.
     *
     * @return list<int>
     */
    private function recipients(RealtimeSignal $signal): array
    {
        return collect($signal->broadcastOn())
            ->map(fn (PrivateChannel $channel) => (int) Str::after($channel->name, 'private-App.Models.User.'))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function ids(User ...$users): array
    {
        return collect($users)->pluck('id')->sort()->values()->all();
    }

    private function order(?int $responsibleId = null): Order
    {
        $source = $this->makeSource($this->showroom, ['channel_ids' => $this->channels('chat')]);

        return app(OrderManager::class)->create($this->admin, [
            'showroom_id' => $source->showroom_id,
            'source_id' => $source->id,
            'contact_channel_id' => ContactChannel::query()->where('code', 'chat')->value('id'),
            'contact_name' => 'Клиент',
            'phones' => [sprintf('7999500%04d', ++$this->phone)],
            'phone_names' => [],
            'car_interest' => null,
            'responsible_id' => $responsibleId,
        ]);
    }

    private function reassign(Order $order, User $responsible): void
    {
        DB::transaction(function () use ($order, $responsible) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $locked->responsible_id = $responsible->id;
            $locked->bumpLockVersion()->save();
        });
    }
}
