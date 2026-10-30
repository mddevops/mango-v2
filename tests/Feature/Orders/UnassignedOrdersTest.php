<?php

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\ContactChannel;
use App\Models\Order;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\User;
use App\Services\OrderManager;
use App\Support\PermissionCatalog as P;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

class UnassignedOrdersTest extends TestCase
{
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    private const UNASSIGNED = ['filter' => [['field' => 'assignment', 'operator' => 'is', 'values' => ['unassigned']]]];

    private User $admin;

    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
        $this->admin = $this->administrator();
    }

    public function test_the_filter_shows_every_unassigned_order_within_the_access_scope_only(): void
    {
        $own = Showroom::factory()->create();
        $foreign = Showroom::factory()->create();
        $manager = $this->employeeWith('unassigned_manager', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_UPDATE], [$own]);
        $operator = $this->employeeWith('unassigned_operator', [P::ORDERS_VIEW, P::ORDERS_UPDATE], [$own]);

        $direct = $this->order($own, 'direct-call');
        $callback = $this->order($own, ContactChannel::CALLBACK);
        $assigned = $this->order($own, 'chat', $manager->id);
        $this->order($foreign, 'chat');

        $this->actingAs($manager)->get(route('orders.index', self::UNASSIGNED))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === [$direct->id, $callback->id])
                ->where('orders.data.0.can.take', true)
                ->where('orders.data.0.can.transfer', false));

        $this->actingAs($manager)->get(route('orders.index', ['filter' => [['field' => 'assignment', 'operator' => 'is_not', 'values' => ['unassigned']]]]))
            ->assertInertia(fn (Assert $page) => $page->where('orders.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$assigned->id]));

        $this->actingAs($operator)->get(route('orders.index', self::UNASSIGNED))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));
    }

    public function test_taking_an_order_makes_the_employee_responsible_once(): void
    {
        $own = Showroom::factory()->create();
        $foreign = Showroom::factory()->create();
        $first = $this->employeeWith('unassigned_manager', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_UPDATE], [$own], ['name' => 'Первый']);
        $second = $this->employeeWith('unassigned_manager', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_UPDATE], [$own], ['name' => 'Второй']);
        $reader = $this->employeeWith('unassigned_reader', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL], [$own]);
        $operator = $this->employeeWith('unassigned_operator', [P::ORDERS_VIEW, P::ORDERS_UPDATE], [$own]);
        $stranger = $this->employeeWith('unassigned_manager', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_UPDATE], [$foreign]);
        $order = $this->order($own, 'chat');

        $this->actingAs($reader)->post(route('orders.take', $order->id))->assertForbidden();
        $this->actingAs($operator)->post(route('orders.take', $order->id))->assertNotFound();
        $this->actingAs($stranger)->post(route('orders.take', $order->id))->assertNotFound();

        $this->actingAs($first)->post(route('orders.take', $order->id))->assertSessionHasNoErrors();
        $this->actingAs($second)->post(route('orders.take', $order->id))->assertSessionHasErrors(['responsible_id' => OrderManager::ALREADY_TAKEN]);

        $order->refresh();
        $this->assertSame($first->id, $order->responsible_id);
        $this->assertSame(1, $order->lock_version);
        $audit = AuditLog::query()->where('event', 'order.responsible_changed')->sole();
        $this->assertSame([null, 'Первый'], $audit->changes['responsible']);
        $this->assertSame($first->id, $audit->actor_id);
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

    private function order(Showroom $showroom, string $channel, ?int $responsibleId = null): Order
    {
        $source = $this->makeSource($showroom, ['channel_ids' => $this->channels('callback-request', 'direct-call', 'chat')]);

        return app(OrderManager::class)->create($this->admin, [
            'showroom_id' => $source->showroom_id,
            'source_id' => $source->id,
            'contact_channel_id' => ContactChannel::query()->where('code', $channel)->value('id'),
            'contact_name' => 'Клиент',
            'phones' => [sprintf('7999400%04d', ++$this->phone)],
            'phone_names' => [],
            'car_interest' => null,
            'responsible_id' => $responsibleId,
        ]);
    }
}
