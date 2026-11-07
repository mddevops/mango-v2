<?php

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\User;
use App\Models\VisitResult;
use App\Support\PermissionCatalog as P;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

class VisitResultsTest extends TestCase
{
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_values_lifecycle_and_permissions(): void
    {
        $this->assertSame(
            ['Консультация нал', 'Консультация кредит', 'Заявка отказ', 'Заявка ФССП', 'Заявка решение', 'Продажа нал'],
            VisitResult::query()->orderBy('sort_order')->pluck('name')->all(),
        );

        $admin = $this->administrator();
        $this->actingAs($admin)->post(route('visit-results.store'), ['name' => 'Продажа кредит'])->assertSessionHasNoErrors();
        $result = VisitResult::query()->where('name', 'Продажа кредит')->sole();
        $this->actingAs($admin)->patch(route('visit-results.status', $result->id), ['active' => false, 'lock_version' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($result->fresh()->active);
        $this->assertSame(['visit_result.created', 'visit_result.deactivated'], AuditLog::query()->orderBy('id')->pluck('event')->all());

        $this->actingAs($admin)->get(route('visit-results.index'))
            ->assertInertia(fn (Assert $page) => $page->component('order-references/visit-results')->has('items.data', 7));

        $own = Showroom::factory()->create();
        Role::query()->create(['name' => 'order_viewer', 'label' => 'Просмотр справочников', 'guard_name' => 'web'])
            ->syncPermissions([P::ORDER_REFERENCES_VIEW]);
        $viewer = $this->employee('order_viewer', [$own]);
        $this->actingAs($viewer)->get(route('visit-results.index'))->assertOk();
        $this->actingAs($viewer)->post(route('visit-results.store'), ['name' => 'Своё'])->assertForbidden();
        $this->actingAs($viewer)->patch(route('visit-results.status', $result->id), ['active' => true, 'lock_version' => 1])->assertForbidden();
    }

    public function test_status_requiring_visit_result_needs_arrival_and_active_result(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $worker = $this->worker($showroom);
        $arrived = OrderStatus::query()->where('name', 'Приехал')->sole();
        $this->assertTrue($arrived->requires_visit_result && $arrived->requires_arrival_at);

        $sale = VisitResult::query()->where('name', 'Продажа нал')->sole();
        $disabled = VisitResult::query()->where('name', 'Заявка ФССП')->sole();
        $disabled->forceFill(['active' => false])->save();

        $this->actingAs($worker)->post(route('orders.store'), $this->payload($source, ['order_status_id' => $arrived->id]))
            ->assertSessionHasErrors(['arrival_at', 'visit_result_id']);
        $this->actingAs($worker)->post(route('orders.store'), $this->payload($source, ['order_status_id' => $arrived->id, 'arrival_at' => '2026-11-03T12:00', 'visit_result_id' => $disabled->id]))
            ->assertSessionHasErrors('visit_result_id');
        $this->actingAs($worker)->post(route('orders.store'), $this->payload($source, ['order_status_id' => $arrived->id, 'arrival_at' => '2026-11-03T12:00', 'visit_result_id' => $sale->id]))
            ->assertSessionHasNoErrors();

        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame($sale->id, $order->visit_result_id);

        $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayload($order, ['visit_result_id' => null]))
            ->assertSessionHasErrors('visit_result_id');

        $credit = VisitResult::query()->where('name', 'Консультация кредит')->sole();
        $this->actingAs($worker)->put(route('orders.update', $order->id), $this->updatePayload($order, ['visit_result_id' => $credit->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame(
            ['visit_result' => ['Продажа нал', 'Консультация кредит']],
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
            'phones' => [['number' => sprintf('+7 999 200-%02d-%02d', intdiv(++$sequence, 100), $sequence % 100), 'name' => null]],
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
            'contact_name' => $order->contact_name,
            'phones' => array_map(fn (array $phone) => ['number' => $phone['phone'], 'name' => $phone['name']], $order->contactPhones()),
            'order_status_id' => $order->order_status_id,
            'arrival_at' => $order->arrival_at?->setTimezone($order->showroom->timezone)->format('Y-m-d\TH:i'),
            'visit_result_id' => $order->visit_result_id,
            'lock_version' => $order->lock_version,
            ...$overrides,
        ];
    }
}
