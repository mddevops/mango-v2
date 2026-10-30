<?php

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\OrderStatus;
use App\Models\Role;
use App\Models\Showroom;
use App\Support\PermissionCatalog as P;
use Database\Seeders\OrderReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\TestCase;

class OrderStatusesTest extends TestCase
{
    use BuildsOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(OrderReferenceDataSeeder::class);
    }

    public function test_seeded_statuses_follow_legacy_order_and_defaults(): void
    {
        $this->assertSame(
            ['Новая', 'В работе', 'Не отвечает', 'Одобрить', 'Одобренный', 'Приедет', 'Приехал', 'Корзина', 'Повтор', 'ТО', 'Перезвон', 'Не ликвид', 'Повтор ДИ', 'Передано'],
            OrderStatus::query()->orderBy('sort_order')->orderBy('id')->pluck('name')->all(),
        );
        $this->assertSame([], OrderStatus::query()->where('active', false)->pluck('name')->all());
        $this->assertSame(['Передано'], OrderStatus::query()->where('purpose', 'transferred')->pluck('name')->all());
        $this->assertSame(['Новая'], OrderStatus::query()->where('is_initial', true)->pluck('name')->all());

        $this->assertSame(['Перезвон'], OrderStatus::query()->where('requires_callback_at', true)->pluck('name')->all());
        $this->assertSame(['Приедет'], OrderStatus::query()->where('requires_arrival_at', true)->pluck('name')->all());
        $this->assertSame(['Корзина' => 'trash'], OrderStatus::query()->whereNotNull('required_reason')->get()->mapWithKeys(fn (OrderStatus $status) => [$status->name => $status->required_reason->value])->all());
        $this->assertSame(0, OrderStatus::query()->where('color', '!=', 'gray')->count());
    }

    public function test_repeated_seeding_keeps_user_changes_and_adds_no_duplicates(): void
    {
        $admin = $this->administrator();
        $work = $this->findStatus('В работе');
        $repeat = $this->findStatus('Повтор');
        $callback = $this->findStatus('Перезвон');
        $code = $work->code;

        $this->actingAs($admin)->put(route('order-statuses.update', $work->id), $this->payload($work, ['name' => 'В обработке']))->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch(route('order-statuses.status', $repeat->id), ['active' => false, 'lock_version' => 0])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('order-statuses.update', $callback->id), $this->payload($callback, ['requires_callback_at' => false]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch(route('order-statuses.initial', $work->id), ['lock_version' => $work->fresh()->lock_version])->assertSessionHasNoErrors();

        $this->seed(OrderReferenceDataSeeder::class);

        $this->assertSame(14, OrderStatus::query()->count());
        $this->assertFalse(OrderStatus::query()->where('name', 'В работе')->exists());
        $this->assertSame($code, $work->fresh()->code);
        $this->assertFalse($repeat->fresh()->active);
        $this->assertFalse($callback->fresh()->requires_callback_at);
        $this->assertSame(['В обработке'], OrderStatus::query()->where('is_initial', true)->pluck('name')->all());
    }

    public function test_status_is_created_renamed_and_disabled_with_stable_code_and_audit(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('order-statuses.store'), $this->newPayload(['name' => 'Ждёт документы', 'code' => 'manual']))
            ->assertSessionHasErrors('code');

        $this->actingAs($admin)
            ->post(route('order-statuses.store'), $this->newPayload(['name' => '  Ждёт документы ', 'description' => 'Клиент собирает документы', 'color' => 'violet']))
            ->assertSessionHasNoErrors();

        $status = $this->findStatus('Ждёт документы');
        $code = $status->code;
        $this->assertNotSame('', $code);
        $this->assertSame('violet', $status->color->value);

        $this->actingAs($admin)
            ->put(route('order-statuses.update', $status->id), $this->payload($status, ['name' => 'Ожидает документы']))
            ->assertSessionHasNoErrors();

        $status->refresh();
        $this->assertSame(['Ожидает документы', $code, 1], [$status->name, $status->code, $status->lock_version]);
        $this->assertSame($status->id, OrderStatus::query()->orderByDesc('sort_order')->value('id'), 'A new status goes to the end of the list.');
        $this->assertSame(['Ждёт документы', 'Ожидает документы'], AuditLog::query()->where('event', 'order_status.updated')->sole()->changes['name']);

        $this->actingAs($admin)
            ->put(route('order-statuses.update', $status->id), $this->payload($status, ['name' => 'Устаревшая форма', 'lock_version' => 0]))
            ->assertSessionHasErrors('lock_version');

        $this->actingAs($admin)
            ->post(route('order-statuses.store'), $this->newPayload(['name' => 'Новая']))
            ->assertSessionHasErrors(['name' => OrderStatus::NAME_TAKEN]);

        $this->actingAs($admin)
            ->patch(route('order-statuses.status', $status->id), ['active' => false, 'lock_version' => $status->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertFalse($status->fresh()->active);

        $this->assertSame(
            ['order_status.created', 'order_status.updated', 'order_status.deactivated'],
            AuditLog::query()->where('subject_type', 'order_status')->orderBy('id')->pluck('event')->all(),
        );

        $this->actingAs($admin)->delete('/references/order-statuses/'.$status->id)->assertStatus(405);
    }

    public function test_initial_status_cannot_be_disabled_and_moves_atomically(): void
    {
        $admin = $this->administrator();
        $new = $this->findStatus('Новая');
        $work = $this->findStatus('В работе');
        $handed = $this->findStatus('Передано');

        $this->actingAs($admin)
            ->patch(route('order-statuses.status', $new->id), ['active' => false, 'lock_version' => 0])
            ->assertSessionHasErrors('active');
        $this->assertTrue($new->fresh()->active);

        $this->actingAs($admin)
            ->patch(route('order-statuses.initial', $handed->id), ['lock_version' => 0])
            ->assertSessionHasErrors('is_initial');

        $this->actingAs($admin)
            ->patch(route('order-statuses.initial', $this->findStatus('Перезвон')->id), ['lock_version' => 0])
            ->assertSessionHasErrors('is_initial');

        $this->actingAs($admin)
            ->patch(route('order-statuses.initial', $work->id), ['lock_version' => 5])
            ->assertSessionHasErrors('lock_version');

        $this->actingAs($admin)
            ->patch(route('order-statuses.initial', $work->id), ['lock_version' => 0])
            ->assertSessionHasNoErrors();

        $this->assertSame(['В работе'], OrderStatus::query()->where('is_initial', true)->pluck('name')->all());
        $this->assertSame(1, $new->fresh()->lock_version);
        $this->assertSame(['Новая', 'В работе'], AuditLog::query()->where('event', 'order_status.initial_changed')->sole()->changes['initial_status']);

        $this->actingAs($admin)
            ->patch(route('order-statuses.status', $new->id), ['active' => false, 'lock_version' => 1])
            ->assertSessionHasNoErrors();
        $this->assertFalse($new->fresh()->active);

        $this->actingAs($admin)
            ->put(route('order-statuses.update', $work->id), $this->payload($work, ['requires_arrival_at' => true]))
            ->assertSessionHasErrors('requirements');
        $this->assertFalse($work->fresh()->requires_arrival_at);
    }

    public function test_requirements_are_validated(): void
    {
        $admin = $this->administrator();
        $work = $this->findStatus('В работе');

        $this->actingAs($admin)
            ->post(route('order-statuses.store'), $this->newPayload(['name' => 'Причина', 'required_reason' => 'arrivals']))
            ->assertSessionHasErrors('required_reason');

        $this->actingAs($admin)
            ->post(route('order-statuses.store'), $this->newPayload(['name' => 'Цвет', 'color' => 'orange']))
            ->assertSessionHasErrors('color');

        $this->actingAs($admin)
            ->post(route('order-statuses.store'), $this->newPayload(['name' => 'Флаг', 'requires_callback_at' => 'maybe']))
            ->assertSessionHasErrors('requires_callback_at');

        $this->actingAs($admin)
            ->post(route('order-statuses.store'), $this->newPayload(['name' => 'Начальный', 'is_initial' => true]))
            ->assertSessionHasErrors('is_initial');

        $callback = $this->findStatus('Перезвон');

        $this->actingAs($admin)
            ->put(route('order-statuses.update', $callback->id), $this->payload($callback, ['required_reason' => 'drop', 'requires_arrival_at' => true]))
            ->assertSessionHasNoErrors();

        $callback->refresh();
        $this->assertSame([true, true, 'drop'], [$callback->requires_callback_at, $callback->requires_arrival_at, $callback->required_reason->value]);
        $this->assertSame([null, 'drop'], AuditLog::query()->where('event', 'order_status.updated')->sole()->changes['required_reason']);
        $this->assertSame(0, $work->fresh()->lock_version);
    }

    public function test_list_tab_flag_is_seeded_saved_and_kept_when_omitted(): void
    {
        $admin = $this->administrator();
        $approve = $this->findStatus('Одобрить');

        $this->assertSame(
            ['Новая', 'В работе', 'Не отвечает', 'Приедет', 'Приехал', 'Корзина'],
            OrderStatus::query()->where('show_as_tab', true)->orderBy('sort_order')->pluck('name')->all(),
        );

        $this->actingAs($admin)->put(route('order-statuses.update', $approve->id), $this->payload($approve, ['show_as_tab' => true]))->assertSessionHasNoErrors();
        $this->assertTrue($approve->fresh()->show_as_tab);

        $this->actingAs($admin)->put(route('order-statuses.update', $approve->id), $this->payload($approve->fresh(), ['description' => 'Без флага']))->assertSessionHasNoErrors();
        $this->assertTrue($approve->fresh()->show_as_tab);

        $this->actingAs($admin)->put(route('order-statuses.update', $approve->id), $this->payload($approve->fresh(), ['show_as_tab' => 'да']))->assertSessionHasErrors('show_as_tab');
    }

    public function test_statuses_are_reordered_by_dragging(): void
    {
        $this->actingAs($this->administrator())
            ->patch(route('order-statuses.reorder'), ['ids' => [$this->findStatus('Перезвон')->id, $this->findStatus('Новая')->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['Перезвон', 'В работе', 'Не отвечает', 'Одобрить', 'Одобренный', 'Приедет', 'Приехал', 'Корзина', 'Повтор', 'ТО', 'Новая', 'Не ликвид', 'Повтор ДИ', 'Передано'],
            OrderStatus::query()->orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertSame(range(10, 140, 10), OrderStatus::query()->orderBy('sort_order')->pluck('sort_order')->all());
        $this->assertSame(['Новая, Перезвон', 'Перезвон, Новая'], AuditLog::query()->where('event', 'order_status.reordered')->sole()->changes['order']);
        $this->assertSame(['Новая'], OrderStatus::query()->where('is_initial', true)->pluck('name')->all());
    }

    public function test_managing_requires_permission_and_global_access(): void
    {
        $showroom = Showroom::factory()->create();
        $status = $this->findStatus('В работе');

        Role::query()->create(['name' => 'order_viewer', 'label' => 'Просмотр справочников', 'guard_name' => 'web'])
            ->syncPermissions([P::ORDER_REFERENCES_VIEW]);
        Role::query()->create(['name' => 'source_viewer', 'label' => 'Справочники источников', 'guard_name' => 'web'])
            ->syncPermissions([P::SOURCE_REFERENCES_VIEW]);
        $viewer = $this->employee('order_viewer', [$showroom]);
        $localAdmin = $this->employee('administrator', [$showroom]);
        $director = $this->employee('director', [$showroom]);

        $this->actingAs($director)->get(route('order-statuses.index'))->assertForbidden();
        $this->actingAs($director)->get(route('references.index'))->assertForbidden();
        $this->actingAs($this->employee('source_viewer', [$showroom]))->get(route('references.index'))->assertRedirect(route('source-references.index', 'kinds'));

        $this->actingAs($viewer)
            ->get(route('order-statuses.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('order-references/statuses')
                ->has('items.data', 10)
                ->where('items.meta.total', 14)
                ->where('initial', 'Новая')
                ->where('can.manage', false)
                ->where('auth.abilities.viewOrderReferences', true)
                ->where('auth.abilities.viewSourceReferences', false));
        $this->actingAs($viewer)->get(route('references.index'))->assertRedirect(route('order-statuses.index'));

        foreach ([$viewer, $localAdmin] as $user) {
            $this->actingAs($user)->post(route('order-statuses.store'), $this->newPayload(['name' => 'Чужой']))->assertForbidden();
            $this->actingAs($user)->put(route('order-statuses.update', $status->id), $this->payload($status, ['name' => 'Чужой']))->assertForbidden();
            $this->actingAs($user)->patch(route('order-statuses.status', $status->id), ['active' => false, 'lock_version' => 0])->assertForbidden();
            $this->actingAs($user)->patch(route('order-statuses.initial', $status->id), ['lock_version' => 0])->assertForbidden();
            $this->actingAs($user)->patch(route('order-statuses.reorder'), ['ids' => [$status->id, $this->findStatus('Новая')->id]])->assertForbidden();
        }

        $status->refresh();
        $this->assertSame(['В работе', true, false], [$status->name, $status->active, $status->is_initial]);
        $this->assertSame('Новая', OrderStatus::query()->orderBy('sort_order')->orderBy('id')->value('name'));

        $this->actingAs($this->administrator())
            ->get(route('order-statuses.index', ['search' => 'повтор']))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 2)->where('can.manage', true));
    }

    public function test_managing_requires_full_administrator_not_only_global_access_and_permission(): void
    {
        $status = $this->findStatus('В работе');
        $new = $this->findStatus('Новая');

        Role::query()->create(['name' => 'order_manager', 'label' => 'Управление справочниками заявок', 'guard_name' => 'web'])
            ->syncPermissions([P::ORDER_REFERENCES_VIEW, P::ORDER_REFERENCES_MANAGE]);
        $manager = $this->employee('order_manager', [], ['has_global_access' => true]);

        $this->actingAs($manager)
            ->get(route('order-statuses.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', false));
        $this->actingAs($manager)
            ->get(route('order-reasons.index', 'drop'))
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', false));

        $this->actingAs($manager)->post(route('order-statuses.store'), $this->newPayload(['name' => 'Чужой']))->assertForbidden();
        $this->actingAs($manager)->put(route('order-statuses.update', $status->id), $this->payload($status, ['name' => 'Чужой']))->assertForbidden();
        $this->actingAs($manager)->patch(route('order-statuses.status', $status->id), ['active' => false, 'lock_version' => 0])->assertForbidden();
        $this->actingAs($manager)->patch(route('order-statuses.initial', $status->id), ['lock_version' => 0])->assertForbidden();
        $this->actingAs($manager)->patch(route('order-statuses.reorder'), ['ids' => [$status->id, $new->id]])->assertForbidden();
        $this->actingAs($manager)
            ->post(route('order-reasons.store', 'drop'), ['name' => 'Чужая', 'description' => null, 'comment_required' => false, 'all_showrooms' => true, 'showroom_ids' => []])
            ->assertForbidden();

        $this->assertSame(0, AuditLog::query()->count());

        $admin = $this->administrator();

        $this->actingAs($admin)
            ->get(route('order-statuses.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', true));
        $this->actingAs($admin)
            ->patch(route('order-statuses.reorder'), ['ids' => [$status->id, $new->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame('В работе', OrderStatus::query()->orderBy('sort_order')->value('name'));
    }

    private function findStatus(string $name): OrderStatus
    {
        return OrderStatus::query()->where('name', $name)->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function newPayload(array $overrides = []): array
    {
        return [
            'name' => 'Статус',
            'description' => null,
            'color' => 'gray',
            'requires_callback_at' => false,
            'requires_arrival_at' => false,
            'required_reason' => null,
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(OrderStatus $status, array $overrides = []): array
    {
        $status->refresh();

        return [
            'name' => $status->name,
            'description' => $status->description,
            'color' => $status->color->value,
            'requires_callback_at' => $status->requires_callback_at,
            'requires_arrival_at' => $status->requires_arrival_at,
            'required_reason' => $status->required_reason?->value,
            'lock_version' => $status->lock_version,
            ...$overrides,
        ];
    }
}
