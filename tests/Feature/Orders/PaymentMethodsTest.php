<?php

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\User;
use App\Support\CodeGenerator;
use App\Support\PermissionCatalog as P;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

class PaymentMethodsTest extends TestCase
{
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_legacy_values_are_available_in_order(): void
    {
        $this->assertSame(
            ['Не определено', 'Наличными', 'В кредит', 'Лизинг', 'Не дозвон', 'Повтор', 'Не ликвид', 'ТО'],
            PaymentMethod::query()->orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertSame('Не определено', PaymentMethod::query()->where('code', PaymentMethod::UNDEFINED)->value('name'));
    }

    public function test_lifecycle_reorder_and_audit(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)->post(route('payment-methods.store'), ['name' => 'Наличными'])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post(route('payment-methods.store'), ['name' => ' Рассрочка '])->assertSessionHasNoErrors();

        $method = PaymentMethod::query()->where('name', 'Рассрочка')->sole();
        $this->assertSame(CodeGenerator::base('Рассрочка', 'payment'), $method->code);
        $this->assertSame(PaymentMethod::query()->max('sort_order'), $method->sort_order);

        $this->actingAs($admin)->put(route('payment-methods.update', $method->id), ['name' => 'Рассрочка банка', 'lock_version' => 0])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('payment-methods.update', $method->id), ['name' => 'Старая форма', 'lock_version' => 0])->assertSessionHasErrors('lock_version');
        $this->actingAs($admin)->patch(route('payment-methods.status', $method->id), ['active' => false, 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['Рассрочка банка', false, $method->code], [$method->fresh()->name, $method->fresh()->active, $method->fresh()->code]);

        $ids = PaymentMethod::query()->orderBy('sort_order')->limit(2)->pluck('id')->all();
        $this->actingAs($admin)->patch(route('payment-methods.reorder'), ['ids' => array_reverse($ids)])->assertSessionHasNoErrors();
        $this->assertSame(array_reverse($ids), PaymentMethod::query()->orderBy('sort_order')->limit(2)->pluck('id')->all());

        $this->assertSame(
            ['payment_method.created', 'payment_method.updated', 'payment_method.deactivated', 'payment_method.reordered'],
            AuditLog::query()->orderBy('id')->pluck('event')->all(),
        );

        $this->actingAs($admin)->get(route('payment-methods.index'))
            ->assertInertia(fn (Assert $page) => $page->component('order-references/payment-methods')->has('items.data', 9));
    }

    public function test_managing_requires_permission(): void
    {
        $own = Showroom::factory()->create();
        $method = PaymentMethod::query()->where('name', 'Лизинг')->sole();

        Role::query()->create(['name' => 'order_viewer', 'label' => 'Просмотр справочников', 'guard_name' => 'web'])
            ->syncPermissions([P::ORDER_REFERENCES_VIEW]);

        $this->actingAs($this->employee('director', [$own]))->get(route('payment-methods.index'))->assertForbidden();

        $viewer = $this->employee('order_viewer', [$own]);
        $this->actingAs($viewer)->get(route('payment-methods.index'))->assertOk();
        $this->actingAs($viewer)->post(route('payment-methods.store'), ['name' => 'Своё'])->assertForbidden();
        $this->actingAs($viewer)->put(route('payment-methods.update', $method->id), ['name' => 'Чужое', 'lock_version' => 0])->assertForbidden();
        $this->actingAs($viewer)->patch(route('payment-methods.status', $method->id), ['active' => false, 'lock_version' => 0])->assertForbidden();
        $this->actingAs($viewer)->patch(route('payment-methods.reorder'), ['ids' => [$method->id]])->assertForbidden();

        $this->assertSame(['Лизинг', true], [$method->fresh()->name, $method->fresh()->active]);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_order_keeps_payment_method_and_accepts_only_active_ones(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $worker = $this->worker($showroom);
        $credit = PaymentMethod::query()->where('name', 'В кредит')->sole();
        $cash = PaymentMethod::query()->where('name', 'Наличными')->sole();
        $disabled = PaymentMethod::query()->where('name', 'ТО')->sole();
        $disabled->forceFill(['active' => false])->save();

        $this->actingAs($worker)->post(route('orders.store'), $this->payload($source, ['payment_method_id' => $disabled->id]))->assertSessionHasErrors('payment_method_id');
        $this->actingAs($worker)->post(route('orders.store'), $this->payload($source))->assertSessionHasNoErrors();
        $this->assertNull(Order::query()->latest('id')->firstOrFail()->payment_method_id);

        $this->actingAs($worker)->post(route('orders.store'), $this->payload($source, ['payment_method_id' => $credit->id]))->assertSessionHasNoErrors();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame($credit->id, $order->payment_method_id);

        $credit->forceFill(['active' => false])->save();
        $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayload($order, ['payment_method_id' => $disabled->id]))->assertSessionHasErrors('payment_method_id');
        $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayload($order, ['contact_name' => 'Новое имя']))->assertSessionHasNoErrors();
        $this->assertSame($credit->id, $order->fresh()->payment_method_id);

        $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayload($order, ['payment_method_id' => $cash->id]))->assertSessionHasNoErrors();
        $this->assertSame($cash->id, $order->fresh()->payment_method_id);
        $this->assertSame(
            ['payment_method' => ['В кредит', 'Наличными']],
            AuditLog::query()->where('event', 'order.updated')->latest('id')->firstOrFail()->changes,
        );
    }

    private function worker(Showroom $showroom): User
    {
        Role::query()->firstOrCreate(['name' => 'order_worker', 'guard_name' => 'web'], ['label' => 'order_worker'])
            ->syncPermissions([P::ORDERS_VIEW, P::ORDERS_CREATE, P::ORDERS_UPDATE]);

        return $this->employee('order_worker', [$showroom]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Source $source, array $overrides = []): array
    {
        static $sequence = 0;

        return [
            'showroom_id' => $source->showroom_id,
            'source_id' => $source->id,
            'contact_channel_id' => $source->channels()->orderBy('contact_channels.id')->value('contact_channels.id'),
            'phones' => [['number' => sprintf('+7 999 100-%02d-%02d', intdiv(++$sequence, 100), $sequence % 100), 'name' => null]],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayload(Order $order, array $overrides = []): array
    {
        $order->refresh();

        return [
            'contact_channel_id' => $order->contact_channel_id,
            'payment_method_id' => $order->payment_method_id,
            'contact_name' => $order->contact_name,
            'phones' => array_map(fn (array $phone) => ['number' => $phone['phone'], 'name' => $phone['name']], $order->contactPhones()),
            'order_status_id' => $order->order_status_id,
            'lock_version' => $order->lock_version,
            ...$overrides,
        ];
    }
}
