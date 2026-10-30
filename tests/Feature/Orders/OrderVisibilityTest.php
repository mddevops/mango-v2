<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\User;
use App\Support\PermissionCatalog as P;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

/**
 * Without `orders.view_all` an employee sees only the orders he is responsible for, in
 * accessible showrooms; the permission widens that to every order of those showrooms.
 */
class OrderVisibilityTest extends TestCase
{
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    private const OPERATOR = [P::ORDERS_VIEW, P::ORDERS_CREATE, P::ORDERS_UPDATE, P::ORDERS_COMMENT];

    private int $phoneSequence = 0;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
        $this->admin = $this->administrator();
    }

    public function test_operator_sees_only_his_orders_and_not_foreign_or_unassigned(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $operator = $this->employeeWith('vis_operator', self::OPERATOR, [$showroom]);
        $colleague = $this->employeeWith('vis_operator', self::OPERATOR, [$showroom]);

        $mine = $this->orderFor($source, $operator->id, '+7 911 000-00-01');
        $theirs = $this->orderFor($source, $colleague->id, '+7 911 000-00-02');
        $unassigned = $this->orderFor($source, null, '+7 911 000-00-03');

        $this->actingAs($operator)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $mine->id)
                ->where('orders.meta.total', 1));

        foreach (['+7 911 000-00-02', '+7 911 000-00-03'] as $phone) {
            $this->actingAs($operator)->get(route('orders.index', ['search' => $phone]))
                ->assertInertia(fn (Assert $page) => $page->has('orders.data', 0)->where('orders.meta.total', 0));
        }

        $this->actingAs($operator)->get(route('orders.show', $mine->id))->assertOk();

        foreach ([$theirs, $unassigned] as $hidden) {
            $this->actingAs($operator)->get(route('orders.show', $hidden->id))->assertNotFound();
            $this->actingAs($operator)->put(route('orders.update', $hidden->id), $this->updatePayloadFor($hidden))->assertNotFound();
            $this->actingAs($operator)->post(route('orders.comments.store', $hidden->id), ['body' => 'Текст'])->assertNotFound();
        }
    }

    public function test_view_all_opens_every_order_of_accessible_showrooms_only(): void
    {
        $own = Showroom::factory()->create();
        $foreign = Showroom::factory()->create();
        $ownSource = $this->makeSource($own);
        $senior = $this->employeeWith('vis_senior', [...self::OPERATOR, P::ORDERS_VIEW_ALL], [$own]);
        $operator = $this->employeeWith('vis_operator', self::OPERATOR, [$own, $foreign]);

        $assigned = $this->orderFor($ownSource, $operator->id);
        $unassigned = $this->orderFor($ownSource, null);
        $elsewhere = $this->orderFor($this->makeSource($foreign), $senior->id);

        $this->actingAs($senior)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.meta.total', 2)
                ->where('orders.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === [$assigned->id, $unassigned->id]));

        $this->actingAs($senior)->get(route('orders.show', $unassigned->id))->assertOk();
        $this->actingAs($senior)->get(route('orders.show', $elsewhere->id))->assertNotFound();
    }

    public function test_history_comments_and_global_audit_follow_the_responsible_scope(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $operator = $this->employeeWith('vis_historian', [...self::OPERATOR, P::ORDERS_HISTORY, P::AUDIT_VIEW], [$showroom]);
        $mine = $this->orderFor($source, $operator->id);
        $foreign = $this->orderFor($source, null);

        $this->actingAs($this->admin)->post(route('orders.comments.store', $foreign->id), ['body' => 'Чужой комментарий'])->assertSessionHasNoErrors();

        $this->actingAs($operator)->get(route('orders.show', $mine->id))
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly('history', fn (Assert $reload) => $reload->has('history.data'))
                ->reloadOnly('comments', fn (Assert $reload) => $reload->has('comments.data', 0)));

        $this->actingAs($operator)->get(route('orders.show', $foreign->id))->assertNotFound();

        $this->actingAs($operator)->get(route('audit.index'))
            ->assertInertia(fn (Assert $page) => $page->where('entries.data', function ($entries) {
                $orders = collect($entries)->where('subject_type', 'Заявка');

                return $orders->where('event', 'order.created')->count() === 1
                    && $orders->doesntContain('event', 'order.comment_added');
            }));
    }

    public function test_previous_orders_show_only_accessible_ones_and_keep_the_repeat_counter(): void
    {
        $own = Showroom::factory()->create();
        $second = Showroom::factory()->create();
        $source = $this->makeSource($own);
        $operator = $this->employeeWith('vis_previous', [...self::OPERATOR, P::ORDERS_PREVIOUS, P::ORDERS_CROSS_SHOWROOM], [$own, $second]);
        $colleague = $this->employeeWith('vis_operator', self::OPERATOR, [$own, $second]);
        $phone = '+7 922 000-00-01';

        $hiddenEarlier = $this->orderFor($source, $colleague->id, $phone);
        $visibleEarlier = $this->orderFor($source, $operator->id, $phone);
        $hiddenCross = $this->orderFor($this->makeSource($second), null, $phone);
        $visibleCross = $this->orderFor($this->makeSource($second), $operator->id, $phone);
        $current = $this->orderFor($source, $operator->id, $phone);

        $this->actingAs($operator)->get(route('orders.show', $current->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.repeat_number', 2)
                ->has('previous', 1)
                ->where('previous.0.id', $visibleEarlier->id)
                ->reloadOnly('crossShowroom', fn (Assert $reload) => $reload
                    ->has('crossShowroom', 1)
                    ->where('crossShowroom.0.id', $visibleCross->id)));

        $this->assertNotSame($hiddenEarlier->id, $visibleEarlier->id);
        $this->assertNotSame($hiddenCross->id, $visibleCross->id);
    }

    public function test_manual_creation_without_wider_rights_is_assigned_to_the_author(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $operator = $this->employeeWith('vis_operator', self::OPERATOR, [$showroom]);
        $colleague = $this->employeeWith('vis_operator', self::OPERATOR, [$showroom]);

        $this->actingAs($operator)->post(route('orders.store'), $this->createPayload($source, ['responsible_id' => $colleague->id]))
            ->assertSessionHasErrors('responsible_id');

        $response = $this->actingAs($operator)->post(route('orders.store'), $this->createPayload($source));
        $order = Order::query()->latest('id')->firstOrFail();

        $response->assertSessionHasNoErrors()->assertRedirect(route('orders.show', $order->id));
        $this->assertSame($operator->id, $order->responsible_id);
        $this->actingAs($operator)->get(route('orders.show', $order->id))->assertOk();
    }

    public function test_responsible_substitution_cannot_take_a_foreign_order_and_handing_over_leaves_the_card(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $assigner = $this->employeeWith('vis_assigner', [...self::OPERATOR, P::ORDERS_ASSIGN], [$showroom]);
        $colleague = $this->employeeWith('vis_operator', self::OPERATOR, [$showroom]);

        $theirs = $this->orderFor($source, $colleague->id);
        $unassigned = $this->orderFor($source, null);

        foreach ([$theirs, $unassigned] as $foreign) {
            $before = $foreign->responsible_id;

            $this->actingAs($assigner)->put(route('orders.update', $foreign->id), $this->updatePayloadFor($foreign, ['responsible_id' => $assigner->id]))
                ->assertNotFound();
            $this->assertSame($before, $foreign->fresh()->responsible_id);
        }

        $mine = $this->orderFor($source, $assigner->id);

        $this->actingAs($assigner)->from(route('orders.show', $mine->id))
            ->put(route('orders.update', $mine->id), $this->updatePayloadFor($mine, ['responsible_id' => $colleague->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('orders.index'));

        $this->assertSame($colleague->id, $mine->fresh()->responsible_id);
        $this->actingAs($assigner)->get(route('orders.show', $mine->id))->assertNotFound();
    }

    private function orderFor(Source $source, ?int $responsibleId, ?string $phone = null): Order
    {
        $this->actingAs($this->admin)
            ->post(route('orders.store'), $this->createPayload($source, ['responsible_id' => $responsibleId, ...($phone ? ['phones' => [$phone]] : [])]))
            ->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
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

    /**
     * @return array<string, mixed>
     */
    private function createPayload(Source $source, array $overrides = []): array
    {
        $payload = [
            'showroom_id' => $source->showroom_id,
            'source_id' => $source->id,
            'contact_channel_id' => $source->channels()->orderBy('contact_channels.id')->value('contact_channels.id'),
            'contact_name' => 'Клиент',
            'phones' => [sprintf('+7 999 100-%02d-%02d', intdiv(++$this->phoneSequence, 100), $this->phoneSequence % 100)],
            'car_interest' => null,
            ...$overrides,
        ];
        $payload['phones'] = array_map(fn (string $phone) => ['number' => $phone, 'name' => null], $payload['phones']);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayloadFor(Order $order, array $overrides = []): array
    {
        $order->refresh();

        return [
            'contact_channel_id' => $order->contact_channel_id,
            'contact_name' => $order->contact_name,
            'phones' => array_map(fn (array $phone) => ['number' => $phone['phone'], 'name' => $phone['name']], $order->contactPhones()),
            'car_interest' => $order->car_interest,
            'order_status_id' => $order->order_status_id,
            'callback_at' => null,
            'arrival_at' => null,
            'reason_id' => null,
            'reason_comment' => null,
            'lock_version' => $order->lock_version,
            ...$overrides,
        ];
    }
}
