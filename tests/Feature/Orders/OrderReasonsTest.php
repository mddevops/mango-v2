<?php

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\OrderDropReason;
use App\Models\OrderTrashReason;
use App\Models\Role;
use App\Models\Showroom;
use App\Support\PermissionCatalog as P;
use Database\Seeders\OrderReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\TestCase;

class OrderReasonsTest extends TestCase
{
    use BuildsOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_seeded_reasons_are_trimmed_separate_and_not_linked_by_legacy_ids(): void
    {
        Showroom::factory()->count(14)->create();
        $this->seed(OrderReferenceDataSeeder::class);

        $this->assertSame(19, OrderTrashReason::query()->count());
        $this->assertSame(14, OrderDropReason::query()->count());
        $this->assertTrue(OrderTrashReason::query()->where('name', 'Отзывы')->exists());
        $this->assertTrue(OrderDropReason::query()->where('name', 'Изменился способ оплаты')->exists());
        $this->assertTrue(OrderDropReason::query()->where('name', 'Был у нас в салоне  либо есть в нашем салоне')->exists());

        foreach ([OrderTrashReason::class, OrderDropReason::class] as $class) {
            $this->assertSame(0, $class::query()->whereRaw('name <> trim(name)')->count());
            $this->assertSame(['Другое'], $class::query()->where('comment_required', true)->pluck('name')->all());
        }

        $restricted = ['Не поедет в Москву', 'Не поедет в Волгоград', 'Не поедет в Нижний Новгород', 'Не поедет в Ростов'];
        $this->assertSame($restricted, OrderTrashReason::query()->where('active', false)->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame($restricted, OrderTrashReason::query()->where('all_showrooms', false)->orderBy('sort_order')->pluck('name')->all());
        $this->assertStringContainsString('Требуется настроить область применения', OrderTrashReason::query()->where('name', 'Не поедет в Ростов')->value('description'));
        $this->assertSame(0, OrderDropReason::query()->where(fn ($query) => $query->where('active', false)->orWhere('all_showrooms', false))->count());

        $this->assertSame(0, DB::table('order_trash_reason_showroom')->count());
        $this->assertSame(0, DB::table('order_drop_reason_showroom')->count());
    }

    public function test_repeated_seeding_keeps_user_changes(): void
    {
        $this->seed(OrderReferenceDataSeeder::class);
        $admin = $this->administrator();
        $showroom = Showroom::factory()->create();
        $moscow = $this->reason(OrderTrashReason::class, 'Не поедет в Москву');
        $other = $this->reason(OrderDropReason::class, 'Другое');
        $cheaper = $this->reason(OrderDropReason::class, 'Нашел дешевле');

        $this->actingAs($admin)->put(route('order-reasons.update', ['trash', $moscow->id]), $this->payload($moscow, ['all_showrooms' => false, 'showroom_ids' => [$showroom->id], 'description' => null]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('order-reasons.update', ['drop', $other->id]), $this->payload($other, ['name' => 'Иное', 'comment_required' => false]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch(route('order-reasons.status', ['drop', $cheaper->id]), ['active' => false, 'lock_version' => 0])->assertSessionHasNoErrors();

        $this->seed(OrderReferenceDataSeeder::class);

        $this->assertSame(19, OrderTrashReason::query()->count());
        $this->assertSame(14, OrderDropReason::query()->count());
        $this->assertFalse(OrderDropReason::query()->where('name', 'Другое')->exists());
        $this->assertFalse($other->fresh()->comment_required);
        $this->assertFalse($cheaper->fresh()->active);
        $this->assertNull($moscow->fresh()->description);
        $this->assertSame([$showroom->id], $moscow->fresh()->showrooms()->pluck('showrooms.id')->all());
    }

    public function test_reason_lifecycle_scope_activation_and_audit(): void
    {
        $this->seed(OrderReferenceDataSeeder::class);
        $admin = $this->administrator();
        $north = Showroom::factory()->create(['name' => 'Север']);
        $south = Showroom::factory()->create(['name' => 'Юг']);
        $closed = Showroom::factory()->create(['name' => 'Закрыт', 'active' => false]);

        $this->actingAs($admin)
            ->post(route('order-reasons.store', 'trash'), $this->newPayload(['name' => 'Без скоупа', 'all_showrooms' => false]))
            ->assertSessionHasErrors('showroom_ids');

        $this->actingAs($admin)
            ->post(route('order-reasons.store', 'trash'), $this->newPayload(['name' => 'Закрытый салон', 'all_showrooms' => false, 'showroom_ids' => [$closed->id]]))
            ->assertSessionHasErrors('showroom_ids');

        $this->actingAs($admin)
            ->post(route('order-reasons.store', 'trash'), $this->newPayload(['name' => ' Нет машины ', 'all_showrooms' => false, 'showroom_ids' => [$north->id], 'comment_required' => true]))
            ->assertSessionHasNoErrors();

        $reason = $this->reason(OrderTrashReason::class, 'Нет машины');
        $code = $reason->code;
        $this->assertTrue($reason->comment_required);
        $this->assertSame('Север', AuditLog::query()->where('event', 'order_trash_reason.created')->sole()->changes['showrooms']);
        $this->assertFalse(OrderDropReason::query()->where('name', 'Нет машины')->exists());

        $this->actingAs($admin)
            ->put(route('order-reasons.update', ['trash', $reason->id]), $this->payload($reason, ['name' => 'Нет автомобиля', 'showroom_ids' => [$north->id, $south->id]]))
            ->assertSessionHasNoErrors();

        $reason->refresh();
        $this->assertSame(['Нет автомобиля', $code, 1], [$reason->name, $reason->code, $reason->lock_version]);
        $changes = AuditLog::query()->where('event', 'order_trash_reason.updated')->sole()->changes;
        $this->assertSame(['Нет машины', 'Нет автомобиля'], $changes['name']);
        $this->assertSame(['Север', 'Север, Юг'], $changes['showrooms']);

        $this->actingAs($admin)
            ->put(route('order-reasons.update', ['trash', $reason->id]), $this->payload($reason, ['all_showrooms' => false, 'showroom_ids' => []]))
            ->assertSessionHasErrors(['showroom_ids' => 'Выберите салоны или вариант «Все салоны».']);

        $this->actingAs($admin)
            ->put(route('order-reasons.update', ['trash', $reason->id]), $this->payload($reason, ['name' => 'Устаревшая', 'lock_version' => 0]))
            ->assertSessionHasErrors('lock_version');

        $this->actingAs($admin)
            ->put(route('order-reasons.update', ['trash', $reason->id]), $this->payload($reason, ['all_showrooms' => true, 'showroom_ids' => [$north->id]]))
            ->assertSessionHasNoErrors();
        $this->assertTrue($reason->fresh()->all_showrooms);
        $this->assertSame(0, $reason->fresh()->showrooms()->count());

        $this->actingAs($admin)
            ->patch(route('order-reasons.status', ['trash', $reason->id]), ['active' => false, 'lock_version' => $reason->fresh()->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertFalse($reason->fresh()->active);

        $this->actingAs($admin)->delete('/references/order-reasons/trash/'.$reason->id)->assertStatus(405);
        $this->actingAs($admin)->get('/references/order-reasons/arrivals')->assertNotFound();
    }

    public function test_reasons_of_one_type_are_reordered_by_dragging(): void
    {
        $this->seed(OrderReferenceDataSeeder::class);
        $admin = $this->administrator();
        $drops = OrderDropReason::query()->orderBy('sort_order')->pluck('id')->all();
        $trash = OrderTrashReason::query()->orderBy('sort_order')->pluck('sort_order', 'id')->all();
        $last = array_pop($drops);

        $this->actingAs($admin)
            ->patch(route('order-reasons.reorder', 'drop'), ['ids' => [$last, ...$drops]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$last, ...$drops], OrderDropReason::query()->orderBy('sort_order')->pluck('id')->all());
        $this->assertSame($trash, OrderTrashReason::query()->orderBy('sort_order')->pluck('sort_order', 'id')->all());
        $this->assertSame(1, AuditLog::query()->where('event', 'order_drop_reason.reordered')->count());

        $this->actingAs($admin)
            ->patch(route('order-reasons.reorder', 'drop'), ['ids' => [$last, OrderDropReason::query()->max('id') + 1]])
            ->assertSessionHasErrors('ids');
    }

    public function test_legacy_restricted_reason_is_enabled_only_after_choosing_scope(): void
    {
        $this->seed(OrderReferenceDataSeeder::class);
        $admin = $this->administrator();
        $showroom = Showroom::factory()->create();
        $rostov = $this->reason(OrderTrashReason::class, 'Не поедет в Ростов');

        $this->actingAs($admin)
            ->patch(route('order-reasons.status', ['trash', $rostov->id]), ['active' => true, 'lock_version' => 0])
            ->assertSessionHasErrors('active');
        $this->assertFalse($rostov->fresh()->active);

        $this->actingAs($admin)
            ->put(route('order-reasons.update', ['trash', $rostov->id]), $this->payload($rostov, ['showroom_ids' => [$showroom->id]]))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->patch(route('order-reasons.status', ['trash', $rostov->id]), ['active' => true, 'lock_version' => 1])
            ->assertSessionHasNoErrors();
        $this->assertTrue($rostov->fresh()->active);
    }

    public function test_viewers_see_only_reasons_and_showrooms_of_their_scope(): void
    {
        $this->seed(OrderReferenceDataSeeder::class);
        $own = Showroom::factory()->create(['name' => 'Свой']);
        $foreign = Showroom::factory()->create(['name' => 'Чужой']);

        $mixed = $this->reason(OrderTrashReason::class, 'Не поедет в Москву');
        $mixed->showrooms()->sync([$own->id, $foreign->id]);
        $this->reason(OrderTrashReason::class, 'Не поедет в Ростов')->showrooms()->sync([$foreign->id]);

        Role::query()->create(['name' => 'order_viewer', 'label' => 'Просмотр справочников', 'guard_name' => 'web'])
            ->syncPermissions([P::ORDER_REFERENCES_VIEW]);
        $viewer = $this->employee('order_viewer', [$own]);

        $this->actingAs($viewer)
            ->get(route('order-reasons.index', ['trash', 'search' => 'Не поедет']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('order-references/reasons')
                ->has('items.data', 1)
                ->where('items.data.0.name', 'Не поедет в Москву')
                ->where('items.data.0.showrooms', [['id' => $own->id, 'name' => 'Свой', 'active' => true]])
                ->where('showrooms', [['id' => $own->id, 'name' => 'Свой', 'active' => true]])
                ->where('can.manage', false));

        $this->actingAs($viewer)
            ->get(route('order-reasons.index', 'trash'))
            ->assertInertia(fn (Assert $page) => $page->where('items.meta.total', 16));

        $this->actingAs($this->administrator())
            ->get(route('order-reasons.index', ['trash', 'search' => 'Не поедет']))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 4)->has('showrooms', 2)->where('can.manage', true));
    }

    public function test_managing_reasons_requires_permission_and_global_access(): void
    {
        $this->seed(OrderReferenceDataSeeder::class);
        $own = Showroom::factory()->create();
        $reason = $this->reason(OrderDropReason::class, 'Нет денег');

        Role::query()->create(['name' => 'order_viewer', 'label' => 'Просмотр справочников', 'guard_name' => 'web'])
            ->syncPermissions([P::ORDER_REFERENCES_VIEW]);

        $this->actingAs($this->employee('director', [$own]))->get(route('order-reasons.index', 'drop'))->assertForbidden();

        foreach ([$this->employee('order_viewer', [$own]), $this->employee('administrator', [$own])] as $user) {
            $this->actingAs($user)->post(route('order-reasons.store', 'drop'), $this->newPayload(['name' => 'Своя', 'all_showrooms' => false, 'showroom_ids' => [$own->id]]))->assertForbidden();
            $this->actingAs($user)->put(route('order-reasons.update', ['drop', $reason->id]), $this->payload($reason, ['name' => 'Чужое']))->assertForbidden();
            $this->actingAs($user)->patch(route('order-reasons.status', ['drop', $reason->id]), ['active' => false, 'lock_version' => 0])->assertForbidden();
            $this->actingAs($user)->patch(route('order-reasons.reorder', 'drop'), ['ids' => [$reason->id, $this->reason(OrderDropReason::class, 'Другое')->id]])->assertForbidden();
        }

        $this->assertSame(['Нет денег', true], [$reason->fresh()->name, $reason->fresh()->active]);
        $this->assertSame(0, AuditLog::query()->count());
    }

    /**
     * @param  class-string<OrderTrashReason|OrderDropReason>  $class
     */
    private function reason(string $class, string $name): OrderTrashReason|OrderDropReason
    {
        return $class::query()->where('name', $name)->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function newPayload(array $overrides = []): array
    {
        return [
            'name' => 'Причина',
            'description' => null,
            'comment_required' => false,
            'all_showrooms' => true,
            'showroom_ids' => [],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(OrderTrashReason|OrderDropReason $reason, array $overrides = []): array
    {
        $reason->refresh();

        return [
            'name' => $reason->name,
            'description' => $reason->description,
            'comment_required' => $reason->comment_required,
            'all_showrooms' => $reason->all_showrooms,
            'showroom_ids' => $reason->showrooms()->pluck('showrooms.id')->all(),
            'lock_version' => $reason->lock_version,
            ...$overrides,
        ];
    }
}
