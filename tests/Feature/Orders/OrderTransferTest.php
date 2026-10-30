<?php

namespace Tests\Feature\Orders;

use App\Enums\DistributionState;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatusPurpose;
use App\Models\AuditLog;
use App\Models\ContactChannel;
use App\Models\DistributionSchedule;
use App\Models\Order;
use App\Models\OrderComment;
use App\Models\OrderStatus;
use App\Models\OrderTransfer;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\SourceKind;
use App\Models\User;
use App\Services\OrderManager;
use App\Services\OrderStatusManager;
use App\Services\OrderTransferManager;
use App\Support\PermissionCatalog as P;
use Carbon\CarbonImmutable;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

/**
 * Р2 (Moscow, 19:00 boundary) transfers to Р1 (Yekaterinburg, calendar day).
 */
class OrderTransferTest extends TestCase
{
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    private const PHONE = '79994001122';

    private User $admin;

    private Showroom $sender;

    private Showroom $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
        $this->admin = $this->administrator(['name' => 'Админ']);
        $this->sender = Showroom::factory()->create(['name' => 'Р2', 'timezone' => 'Europe/Moscow', 'business_day_boundary_enabled' => true, 'business_day_boundary' => '19:00']);
        $this->receiver = Showroom::factory()->create(['name' => 'Р1', 'timezone' => 'Asia/Yekaterinburg', 'business_day_boundary_enabled' => false]);
    }

    public function test_the_source_order_stays_and_a_new_linked_order_starts_in_the_receiver(): void
    {
        $operator = $this->employeeWith('transfer_operator', [P::ORDERS_VIEW, P::ORDERS_UPDATE], [$this->sender]);
        $earlier = $this->order($this->receiver, 'direct-call', '2026-10-18 12:00', null, self::PHONE);
        $order = $this->order($this->sender, 'direct-call', '2026-10-19 12:00', $operator->id, self::PHONE);
        $this->actingAs($this->admin)->put(route('orders.update', $order->id), [
            'contact_channel_id' => $order->contact_channel_id,
            'contact_name' => 'Иван',
            'phones' => [['number' => self::PHONE, 'name' => null], ['number' => '79994003344', 'name' => 'Жена']],
            'car_interest' => 'Седан',
            'order_status_id' => $order->order_status_id,
            'callback_at' => null,
            'arrival_at' => null,
            'reason_id' => null,
            'reason_comment' => null,
            'lock_version' => 0,
        ])->assertSessionHasNoErrors();
        $order->forceFill(['callback_at' => now()->addDay(), 'reason_comment' => 'Пояснение'])->saveQuietly();
        $this->actingAs($this->admin)->post(route('orders.comments.store', $order->id), ['body' => 'Внутренний комментарий'])->assertSessionHasNoErrors();
        $order->refresh();
        $sourceId = $order->source_id;

        $this->travelTo(CarbonImmutable::parse('2026-10-19 20:30', 'Europe/Moscow'));
        $operation = (string) Str::uuid();
        $payload = ['showroom_id' => $this->receiver->id, 'comment' => 'Клиенту удобнее Р1', 'operation_id' => $operation, 'lock_version' => $order->lock_version];

        $this->actingAs($this->admin)->post(route('orders.transfer', $order->id), $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('orders.transfer', $order->id), $payload)->assertSessionHasNoErrors();

        $transfer = OrderTransfer::query()->sole();
        $this->assertSame([$operation, 'Клиенту удобнее Р1', 'Админ'], [$transfer->operation_id, $transfer->comment, $transfer->user_name]);

        $source = $order->fresh();
        $this->assertSame($this->sender->id, $source->showroom_id);
        $this->assertSame($sourceId, $source->source_id);
        $this->assertSame($operator->id, $source->responsible_id);
        $this->assertSame(OrderStatusPurpose::Transferred, $source->status->purpose);
        $this->assertNotNull($source->callback_at);
        $this->assertSame($order->lock_version + 1, $source->lock_version);

        $new = Order::query()->findOrFail($transfer->target_order_id);
        $this->assertSame($this->receiver->id, $new->showroom_id);
        $this->assertSame(OrderOrigin::Transfer, $new->origin);
        $this->assertSame($source->client_id, $new->client_id);
        $this->assertSame(['Иван', 'Седан', $source->contact_channel_id], [$new->contact_name, $new->car_interest, $new->contact_channel_id]);
        $this->assertSame(self::PHONE, $new->phone);
        $this->assertSame([['79994003344', 'Жена']], $new->additionalPhones()->get()->map(fn ($phone) => [$phone->phone, $phone->name])->all());
        $this->assertSame([self::PHONE, '79994003344'], array_column($source->contactPhones(), 'phone'), 'The source order keeps its contacts.');
        $this->assertTrue($new->status->is_initial);
        $this->assertNull($new->responsible_id);
        $this->assertNull($new->callback_at);
        $this->assertNull($new->arrival_at);
        $this->assertNull($new->reason_comment);
        $this->assertNull($new->distribution_state);
        $this->assertTrue($new->event_at->equalTo(CarbonImmutable::now()));
        $this->assertSame('2026-10-19', $new->business_date, 'The receiver counts a calendar day in its own timezone.');
        $this->assertSame(1, $new->repeat_number, 'Repeats are counted only among the receiver\'s orders.');
        $this->assertSame($earlier->client_id, $new->client_id);

        $internal = Source::query()->findOrFail($new->source_id);
        $this->assertSame([$this->receiver->id, $this->sender->id, 'Другой салон · Р2'], [$internal->showroom_id, $internal->transfer_from_showroom_id, $internal->name]);
        $this->assertSame(OrderTransferManager::KIND_CODE, $internal->kind->code);
        $this->assertTrue($internal->channels()->whereKey($new->contact_channel_id)->exists());

        $this->assertSame(0, OrderComment::query()->where('order_id', $new->id)->count());
        $this->assertSame(['order.created'], AuditLog::query()->where('subject_type', 'order')->where('subject_id', $new->id)->pluck('event')->all());
        $this->assertSame(1, AuditLog::query()->where('event', 'order.transferred')->where('subject_id', $source->id)->count());
        $this->assertSame(1, Order::query()->where('showroom_id', $this->receiver->id)->where('origin', OrderOrigin::Transfer)->count());

        $this->actingAs($this->admin)->post(route('orders.transfer', $order->id), [...$payload, 'operation_id' => (string) Str::uuid(), 'lock_version' => $source->lock_version])
            ->assertSessionHasErrors(['showroom_id' => 'Заявка уже передана в салон «Р1».']);
        $this->assertSame(1, OrderTransfer::query()->count());

        $third = Showroom::factory()->create(['name' => 'Р3']);
        $this->actingAs($this->admin)->post(route('orders.transfer', $new->id), ['showroom_id' => $third->id, 'operation_id' => (string) Str::uuid(), 'lock_version' => $new->fresh()->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, OrderTransfer::query()->count());
        $this->assertSame(1, Source::query()->whereNotNull('transfer_from_showroom_id')->where('showroom_id', $this->receiver->id)->count());
    }

    public function test_a_callback_goes_to_the_receivers_distribution_and_waits_without_candidates(): void
    {
        $waiting = $this->order($this->sender, ContactChannel::CALLBACK, '2026-10-19 10:00');
        $this->transfer($waiting);
        $first = OrderTransfer::query()->sole()->targetOrder;
        $this->assertNull($first->responsible_id);
        $this->assertSame(DistributionState::Pending, $first->distribution_state, 'Nobody to assign: the order stays for a retry.');
        $this->assertSame(OrderStatusPurpose::Transferred, $waiting->fresh()->status->purpose);

        $receiverOperator = $this->employeeWith('transfer_operator', [P::ORDERS_VIEW, P::ORDERS_UPDATE], [$this->receiver]);
        $senderOperator = $this->employeeWith('transfer_operator', [P::ORDERS_VIEW, P::ORDERS_UPDATE], [$this->sender, $this->receiver]);
        $this->schedule($receiverOperator, $this->receiver);
        $this->schedule($senderOperator, $this->sender);

        $callback = $this->order($this->sender, ContactChannel::CALLBACK, '2026-10-19 10:05');
        $this->transfer($callback);
        $this->assertSame($receiverOperator->id, Order::query()->latest('id')->firstOrFail()->responsible_id);

        $direct = $this->order($this->sender, 'direct-call', '2026-10-19 10:10');
        $this->transfer($direct);
        $received = Order::query()->latest('id')->firstOrFail();
        $this->assertNull($received->responsible_id);
        $this->assertNull($received->distribution_state);
    }

    public function test_a_disabled_internal_source_is_reported_before_the_order_changes(): void
    {
        $this->transfer($this->order($this->sender, 'direct-call', '2026-10-19 10:00'));
        Source::query()->whereNotNull('transfer_from_showroom_id')->update(['active' => false]);
        $order = $this->order($this->sender, 'direct-call', '2026-10-19 10:05');

        $this->actingAs($this->admin)->post(route('orders.transfer', $order->id), ['showroom_id' => $this->receiver->id, 'operation_id' => (string) Str::uuid(), 'lock_version' => 0])
            ->assertSessionHasErrors('showroom_id');

        $this->assertSame([0, true], [$order->fresh()->lock_version, $order->fresh()->status->is_initial]);
        $this->assertFalse(Source::query()->whereNotNull('transfer_from_showroom_id')->sole()->active);
        $this->assertSame(1, OrderTransfer::query()->count());
    }

    public function test_a_disabled_kind_blocks_the_transfer_without_partial_changes(): void
    {
        SourceKind::query()->where('code', OrderTransferManager::KIND_CODE)->update(['active' => false]);
        $order = $this->order($this->sender, 'direct-call', '2026-10-19 10:00');
        $orders = Order::query()->count();
        $audit = AuditLog::query()->count();

        $this->actingAs($this->admin)->post(route('orders.transfer', $order->id), ['showroom_id' => $this->receiver->id, 'operation_id' => (string) Str::uuid(), 'lock_version' => 0])
            ->assertSessionHasErrors('showroom_id');

        $order->refresh();
        $this->assertSame([0, true], [$order->lock_version, $order->status->is_initial]);
        $this->assertSame($orders, Order::query()->count());
        $this->assertSame($audit, AuditLog::query()->count());
        $this->assertSame(0, OrderTransfer::query()->count());
        $this->assertFalse(Source::query()->whereNotNull('transfer_from_showroom_id')->exists());
        $this->assertFalse(SourceKind::query()->where('code', OrderTransferManager::KIND_CODE)->value('active'));
    }

    public function test_the_channel_must_be_active_and_allowed_and_is_never_linked_silently(): void
    {
        $kind = SourceKind::query()->where('code', OrderTransferManager::KIND_CODE)->sole();
        $this->assertSame(['callback-request', 'chat', 'direct-call'], $kind->channels()->orderBy('code')->pluck('code')->all());

        $this->seed(ReferenceDataSeeder::class);
        $this->assertSame(1, SourceKind::query()->where('code', OrderTransferManager::KIND_CODE)->count());
        $this->assertSame(['callback-request', 'chat', 'direct-call'], $kind->channels()->orderBy('code')->pluck('code')->all());

        $this->transfer($this->order($this->sender, 'direct-call', '2026-10-19 10:00'));
        $internal = Source::query()->whereNotNull('transfer_from_showroom_id')->sole();
        $this->assertSame(['callback-request', 'chat', 'direct-call'], $internal->channels()->orderBy('code')->pluck('code')->all());

        $direct = ContactChannel::query()->where('code', 'direct-call')->sole();
        $internal->channels()->detach($direct->id);
        $notAllowed = $this->order($this->sender, 'direct-call', '2026-10-19 10:05');
        $this->actingAs($this->admin)->post(route('orders.transfer', $notAllowed->id), ['showroom_id' => $this->receiver->id, 'operation_id' => (string) Str::uuid(), 'lock_version' => 0])
            ->assertSessionHasErrors('showroom_id');
        $this->assertFalse($internal->channels()->whereKey($direct->id)->exists());

        $internal->channels()->attach($direct->id);
        $direct->forceFill(['active' => false])->save();
        $this->actingAs($this->admin)->post(route('orders.transfer', $notAllowed->id), ['showroom_id' => $this->receiver->id, 'operation_id' => (string) Str::uuid(), 'lock_version' => 0])
            ->assertSessionHasErrors('showroom_id');

        $this->assertSame(0, $notAllowed->fresh()->lock_version);
        $this->assertFalse($direct->fresh()->active);
        $this->assertSame(1, OrderTransfer::query()->count());
    }

    public function test_rights_and_linked_cards_are_protected(): void
    {
        $order = $this->order($this->sender, 'direct-call', '2026-10-19 10:00');
        $all = [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_UPDATE, P::ORDERS_CREATE];
        $noRight = $this->employeeWith('transfer_plain', $all, [$this->sender, $this->receiver]);
        $senderOnly = $this->employeeWith('transfer_sender', [...$all, P::ORDERS_TRANSFER], [$this->sender]);
        $receiverOnly = $this->employeeWith('transfer_sender', [...$all, P::ORDERS_TRANSFER], [$this->receiver]);
        $inactive = Showroom::factory()->create(['active' => false]);
        $payload = fn (int $showroom) => ['showroom_id' => $showroom, 'operation_id' => (string) Str::uuid(), 'lock_version' => 0];

        $this->actingAs($noRight)->post(route('orders.transfer', $order->id), $payload($this->receiver->id))->assertForbidden();
        $this->actingAs($receiverOnly)->post(route('orders.transfer', $order->id), $payload($this->receiver->id))->assertNotFound();
        $this->actingAs($senderOnly)->post(route('orders.transfer', $order->id), $payload($this->receiver->id))->assertSessionHasErrors('showroom_id');
        $this->actingAs($this->admin)->post(route('orders.transfer', $order->id), $payload($this->sender->id))->assertSessionHasErrors('showroom_id');
        $this->actingAs($this->admin)->post(route('orders.transfer', $order->id), $payload($inactive->id))->assertSessionHasErrors('showroom_id');
        $this->assertSame(0, OrderTransfer::query()->count());
        $this->assertSame(0, $order->fresh()->lock_version);
        $this->actingAs($senderOnly)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page->where('can.transfer', true)->where('transferShowrooms', []));

        $this->assertThrows(
            fn () => app(OrderManager::class)->update($order->fresh(), [...$this->updateData($order->fresh()), 'order_status_id' => OrderStatus::query()->where('purpose', OrderStatusPurpose::Transferred)->value('id')]),
            ValidationException::class,
        );
        $transferred = OrderStatus::query()->where('purpose', OrderStatusPurpose::Transferred)->sole();
        $this->assertThrows(
            fn () => app(OrderStatusManager::class)->setActive($transferred->id, false, $transferred->lock_version),
            ValidationException::class,
            'Статус используется системой',
        );

        $this->transfer($order);
        $new = OrderTransfer::query()->sole()->targetOrder;

        $this->actingAs($receiverOnly)->get(route('orders.show', $new->id))
            ->assertInertia(fn (Assert $page) => $page->where('order.transfer_in.showroom', 'Р2')->where('order.transfer_in.order_id', null));
        $this->actingAs($senderOnly)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page->where('order.transfer_out.showroom', 'Р1')->where('order.transfer_out.order_id', null)->where('can.transfer', false));
        $this->actingAs($this->admin)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page->where('order.transfer_out.order_id', $new->id));
        $this->actingAs($senderOnly)->get(route('orders.show', $new->id))->assertNotFound();
        $this->actingAs($senderOnly)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page->where('orders.data.0.transferred_to', 'Р1'));
    }

    private function transfer(Order $order): void
    {
        $this->actingAs($this->admin)->post(route('orders.transfer', $order->id), [
            'showroom_id' => $this->receiver->id,
            'operation_id' => (string) Str::uuid(),
            'lock_version' => $order->fresh()->lock_version,
        ])->assertSessionHasNoErrors();
    }

    private function schedule(User $user, Showroom $showroom): void
    {
        $schedule = new DistributionSchedule;
        $schedule->forceFill(['user_id' => $user->id, 'showroom_id' => $showroom->id, 'participates' => true]);

        foreach (DistributionSchedule::WEEKDAYS as $column) {
            $schedule->setAttribute($column, true);
        }

        $schedule->save();
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<Showroom>  $showrooms
     */
    private function employeeWith(string $role, array $permissions, array $showrooms): User
    {
        Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web'], ['label' => $role])->syncPermissions($permissions);

        return $this->employee($role, $showrooms);
    }

    private int $phone = 0;

    private function order(Showroom $showroom, string $channel, string $moscowTime, ?int $responsibleId = null, ?string $phone = null): Order
    {
        $this->travelTo(CarbonImmutable::parse($moscowTime, 'Europe/Moscow'));

        return app(OrderManager::class)->create($this->admin, [
            'showroom_id' => $showroom->id,
            'source_id' => $this->makeSource($showroom)->id,
            'contact_channel_id' => ContactChannel::query()->where('code', $channel)->value('id'),
            'contact_name' => 'Клиент',
            'phones' => [$phone ?? sprintf('7999500%04d', ++$this->phone)],
            'phone_names' => [],
            'car_interest' => null,
            'responsible_id' => $responsibleId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function updateData(Order $order): array
    {
        return [
            'contact_channel_id' => $order->contact_channel_id,
            'contact_name' => $order->contact_name,
            'phones' => array_column($order->contactPhones(), 'phone'),
            'phone_names' => [],
            'car_interest' => null,
            'order_status_id' => $order->order_status_id,
            'callback_at' => null,
            'arrival_at' => null,
            'reason_id' => null,
            'reason_comment' => null,
            'lock_version' => $order->lock_version,
        ];
    }
}
