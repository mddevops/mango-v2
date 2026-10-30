<?php

namespace Tests\Feature\Distribution;

use App\Enums\DistributionResult;
use App\Enums\DistributionState;
use App\Models\AuditLog;
use App\Models\ContactChannel;
use App\Models\DistributionSchedule;
use App\Models\DistributionScheduleException;
use App\Models\Order;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\User;
use App\Services\CallbackDistributor;
use App\Support\PermissionCatalog as P;
use Carbon\CarbonImmutable;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

/**
 * 2026-10-19 is a Monday. Showrooms are in Moscow (UTC+3) with the 19:00 boundary.
 */
class CallbackDistributionTest extends TestCase
{
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    private const EVERY_DAY = [1, 2, 3, 4, 5, 6, 7];

    private User $admin;

    private int $phoneSequence = 0;

    /** @var array<int, Source> */
    private array $sources = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
        $this->admin = $this->administrator(['name' => 'Админ']);
    }

    public function test_weekly_schedule_and_date_exception_decide_who_works(): void
    {
        $showroom = $this->showroom();
        $weekly = $this->operator([$showroom], 'Понедельник');
        $byException = $this->operator([$showroom], 'По исключению');
        $this->enroll($weekly, $showroom, [1], ['2026-10-19' => false]);
        $this->enroll($byException, $showroom, [], ['2026-10-19' => true]);
        $this->operator([$showroom], 'Без графика');

        $this->assertSame($byException->id, $this->distribute($this->callbackOrder($showroom, '2026-10-19 10:00')));
        $this->assertSame($weekly->id, $this->distribute($this->callbackOrder($showroom, '2026-10-26 10:00')));
    }

    public function test_schedules_of_one_employee_in_two_showrooms_are_independent(): void
    {
        $north = $this->showroom();
        $south = $this->showroom();
        $operator = $this->operator([$north, $south], 'Оператор');
        $this->enroll($operator, $north, [1], ['2026-10-20' => true]);
        $this->enroll($operator, $south, [3]);

        $this->assertSame($operator->id, $this->distribute($this->callbackOrder($north, '2026-10-19 10:00')));
        $this->assertNull($this->distribute($this->callbackOrder($south, '2026-10-19 10:00')));
        $this->assertSame($operator->id, $this->distribute($this->callbackOrder($north, '2026-10-20 10:00')));
        $this->assertNull($this->distribute($this->callbackOrder($south, '2026-10-20 10:00')), 'An exception works only in its showroom.');
        $this->assertSame($operator->id, $this->distribute($this->callbackOrder($south, '2026-10-21 10:00')));

        DistributionSchedule::query()->where('showroom_id', $north->id)->update(['participates' => false]);
        $this->assertNull($this->distribute($this->callbackOrder($north, '2026-10-26 10:00')));
        $this->assertSame($operator->id, $this->distribute($this->callbackOrder($south, '2026-10-28 10:00')));
    }

    public function test_a_schedule_does_not_work_without_access_to_its_showroom(): void
    {
        $showroom = $this->showroom();
        $foreign = $this->showroom();
        $operator = $this->operator([$foreign]);
        $this->enroll($operator, $showroom, self::EVERY_DAY);

        $this->assertNull($this->distribute($this->callbackOrder($showroom, '2026-10-19 10:00')));
    }

    public function test_business_day_after_the_boundary_checks_the_next_day(): void
    {
        $showroom = $this->showroom();
        $monday = $this->operator([$showroom], 'Пн');
        $tuesday = $this->operator([$showroom], 'Вт');
        $this->enroll($monday, $showroom, [1]);
        $this->enroll($tuesday, $showroom, [2]);

        $before = $this->callbackOrder($showroom, '2026-10-19 18:59');
        $this->assertSame('2026-10-19', $before->business_date);
        $this->assertSame($monday->id, $this->distribute($before));

        $after = $this->callbackOrder($showroom, '2026-10-19 19:00');
        $this->assertSame('2026-10-20', $after->business_date);
        $this->assertSame($tuesday->id, $this->distribute($after));
    }

    public function test_repeat_keeps_a_suitable_previous_responsible_otherwise_least_load(): void
    {
        $showroom = $this->showroom();
        $first = $this->operator([$showroom], 'Первый');
        $second = $this->operator([$showroom], 'Второй');
        $this->enroll($first, $showroom, self::EVERY_DAY);
        $this->enroll($second, $showroom, self::EVERY_DAY);
        $phone = '+7 911 100-00-01';

        $this->assertSame($first->id, $this->distribute($this->callbackOrder($showroom, '2026-10-19 10:00', $phone)));
        $this->callbackOrder($showroom, '2026-10-19 10:01', null, $first->id);

        $this->assertSame($first->id, $this->distribute($this->callbackOrder($showroom, '2026-10-19 10:02', $phone)), 'The previous responsible keeps the client despite the higher load.');

        DistributionSchedule::query()->where('user_id', $first->id)->update(['participates' => false]);

        $this->assertSame($second->id, $this->distribute($this->callbackOrder($showroom, '2026-10-19 10:03', $phone)));
        $this->assertSame($first->id, Order::query()->orderBy('id')->firstOrFail()->responsible_id);
    }

    public function test_load_and_order_are_counted_per_showroom(): void
    {
        $north = $this->showroom();
        $south = $this->showroom();
        $light = $this->operator([$north, $south], 'Легкий');
        $busy = $this->operator([$north, $south], 'Загруженный');

        foreach ([$north, $south] as $showroom) {
            $this->enroll($light, $showroom, self::EVERY_DAY);
            $this->enroll($busy, $showroom, self::EVERY_DAY);
        }

        $this->callbackOrder($north, '2026-10-19 09:02', null, $busy->id);
        $this->callbackOrder($south, '2026-10-19 09:03', null, $light->id);
        $this->callbackOrder($south, '2026-10-19 09:04', null, $light->id);

        $this->assertSame($light->id, $this->distribute($this->callbackOrder($north, '2026-10-19 10:00')));
        $this->assertSame($busy->id, $this->distribute($this->callbackOrder($south, '2026-10-19 10:01')));

        $lastNorth = DistributionSchedule::query()->where('user_id', $light->id)->where('showroom_id', $north->id)->value('last_assigned_at');
        $this->assertNotNull($lastNorth);
        $this->assertNull(DistributionSchedule::query()->where('user_id', $light->id)->where('showroom_id', $south->id)->value('last_assigned_at'));
    }

    public function test_equal_load_goes_to_who_waited_longest_then_to_the_lower_id(): void
    {
        $showroom = $this->showroom();
        $low = $this->operator([$showroom], 'Меньший id');
        $high = $this->operator([$showroom], 'Больший id');
        $this->enroll($high, $showroom, self::EVERY_DAY);
        $this->enroll($low, $showroom, self::EVERY_DAY);

        $this->assertSame($low->id, $this->distribute($this->callbackOrder($showroom, '2026-10-19 10:00')));
        $this->assertSame($high->id, $this->distribute($this->callbackOrder($showroom, '2026-10-19 10:01')));
        $this->assertSame($low->id, $this->distribute($this->callbackOrder($showroom, '2026-10-19 10:02')));
        $this->assertSame($high->id, $this->distribute($this->callbackOrder($showroom, '2026-10-19 10:03')));
    }

    public function test_without_candidates_the_order_waits_and_the_command_retries_it_without_a_journal(): void
    {
        $this->assertFalse(Schema::hasTable('distribution_decisions'));
        $this->assertFalse(Schema::hasColumn('orders', 'distribution_reason'));

        $showroom = $this->showroom();
        $order = $this->callbackOrder($showroom, '2026-10-19 10:00');

        $this->assertSame(DistributionResult::Waiting, app(CallbackDistributor::class)->request($order->fresh(), $this->admin));
        $order->refresh();
        $this->assertNull($order->responsible_id);
        $this->assertSame(DistributionState::Pending, $order->distribution_state);
        $this->assertSame(0, $order->lock_version);

        $operator = $this->operator([$showroom]);
        $this->enroll($operator, $showroom, [1]);
        $this->travelTo(CarbonImmutable::parse('2026-10-19 11:00', 'Europe/Moscow'));

        $this->artisan('orders:distribute', ['--limit' => 10])->assertSuccessful();

        $order->refresh();
        $this->assertSame($operator->id, $order->responsible_id);
        $this->assertNull($order->distribution_state);
        $this->assertSame(1, $order->lock_version);
        $audit = AuditLog::query()->where('event', 'order.responsible_changed')->sole();
        $this->assertSame(AuditLog::ACTOR_SYSTEM, $audit->actor_kind);
        $this->assertSame([null, $operator->name], $audit->changes['responsible']);
    }

    public function test_an_ended_period_is_left_for_a_manual_decision(): void
    {
        $showroom = $this->showroom();
        $order = $this->callbackOrder($showroom, '2026-10-19 10:00');
        $this->distribute($order);

        $operator = $this->operator([$showroom]);
        $this->enroll($operator, $showroom, self::EVERY_DAY);
        $this->travelTo(CarbonImmutable::parse('2026-10-19 19:00', 'Europe/Moscow'));

        $this->artisan('orders:distribute')->assertSuccessful();

        $order->refresh();
        $this->assertNull($order->responsible_id);
        $this->assertSame(DistributionState::Expired, $order->distribution_state);
        $this->assertSame('2026-10-19', $order->business_date);

        $this->actingAs($this->admin)->post(route('orders.distribute', $order->id))->assertSessionHasNoErrors();
        $this->assertSame(DistributionState::Expired, $order->fresh()->distribution_state);
    }

    public function test_processing_an_assigned_order_again_changes_nothing(): void
    {
        $showroom = $this->showroom();
        $operator = $this->operator([$showroom]);
        $other = $this->operator([$showroom]);
        $this->enroll($operator, $showroom, self::EVERY_DAY);
        $order = $this->callbackOrder($showroom, '2026-10-19 10:00');
        $this->distribute($order);
        $this->enroll($other, $showroom, self::EVERY_DAY);
        DistributionSchedule::query()->where('user_id', $operator->id)->update(['participates' => false]);

        $this->assertNull(app(CallbackDistributor::class)->attempt($order->id));
        $this->artisan('orders:distribute')->assertSuccessful();
        $this->actingAs($this->admin)->post(route('orders.distribute', $order->id))->assertSessionHasErrors('distribution');

        $order->refresh();
        $this->assertSame($operator->id, $order->responsible_id);
        $this->assertSame(1, $order->lock_version);
        $this->assertSame(1, AuditLog::query()->where('event', 'order.responsible_changed')->count());
    }

    public function test_permissions_on_the_distribution_action(): void
    {
        $showroom = $this->showroom();
        $foreign = $this->showroom();
        $order = $this->callbackOrder($showroom, '2026-10-19 10:00');
        $direct = $this->callbackOrder($showroom, '2026-10-19 10:01', null, null, ContactChannel::query()->where('code', 'direct-call')->value('id'));

        $operator = $this->employeeWith('dist_plain', [P::ORDERS_VIEW, P::DISTRIBUTION_RUN], [$showroom]);
        $viewer = $this->employeeWith('dist_viewer', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL], [$showroom]);
        $stranger = $this->employeeWith('dist_runner', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::DISTRIBUTION_RUN], [$foreign]);
        $runner = $this->employeeWith('dist_runner', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::DISTRIBUTION_RUN], [$showroom]);

        $this->actingAs($operator)->post(route('orders.distribute', $order->id))->assertNotFound();
        $this->actingAs($viewer)->post(route('orders.distribute', $order->id))->assertForbidden();
        $this->actingAs($stranger)->post(route('orders.distribute', $order->id))->assertNotFound();
        $this->actingAs($runner)->post(route('orders.distribute', $direct->id))->assertSessionHasErrors(['distribution' => CallbackDistributor::NOT_CALLBACK]);

        $this->actingAs($runner)->post(route('orders.distribute', $order->id))->assertSessionHasNoErrors();
        $this->assertSame(DistributionState::Pending, $order->fresh()->distribution_state);
        $this->actingAs($runner)->get('/distribution/queue')->assertNotFound();
        $this->actingAs($runner)->get('/distribution/log')->assertNotFound();
        $this->assertDatabaseMissing('permissions', ['name' => 'distribution_log.view']);
    }

    public function test_a_senior_employee_manages_schedules_of_his_showroom_only(): void
    {
        $own = $this->showroom();
        $foreign = $this->showroom();
        $operator = $this->operator([$own, $foreign], 'Оператор');
        $outsider = $this->operator([$foreign], 'Чужой');
        $senior = $this->employeeWith('dist_senior', [P::ORDERS_VIEW, P::SCHEDULES_VIEW, P::SCHEDULES_MANAGE], [$own]);
        $reader = $this->employeeWith('dist_reader', [P::ORDERS_VIEW, P::SCHEDULES_VIEW], [$own]);
        $payload = [
            'participates' => true,
            'weekdays' => [true, true, true, true, true, false, false],
            'exceptions' => [['date' => '2026-10-24', 'works' => true]],
            'lock_version' => 0,
        ];

        $this->actingAs($senior)->get(route('distribution.schedules', ['showroom' => $foreign->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('showroom', $own->id)
                ->where('showrooms', fn ($rows) => collect($rows)->pluck('id')->all() === [$own->id])
                ->where('can.manage', true)
                ->where('members.data', fn ($rows) => ! collect($rows)->pluck('id')->contains($outsider->id) && collect($rows)->pluck('id')->contains($operator->id)));

        $this->actingAs($senior)->put(route('distribution.schedules.update', [$own->id, $operator->id]), $payload)->assertSessionHasNoErrors();
        $this->actingAs($senior)->put(route('distribution.schedules.update', [$own->id, $operator->id]), $payload)->assertSessionHasErrors('lock_version');
        $this->actingAs($senior)->put(route('distribution.schedules.update', [$foreign->id, $operator->id]), $payload)->assertForbidden();
        $this->actingAs($senior)->put(route('distribution.schedules.update', [$own->id, $outsider->id]), $payload)
            ->assertSessionHasErrors('participates');
        $this->actingAs($reader)->put(route('distribution.schedules.update', [$own->id, $operator->id]), [...$payload, 'lock_version' => 1])->assertForbidden();
        $this->actingAs($reader)->get(route('distribution.schedules'))->assertInertia(fn (Assert $page) => $page->where('can.manage', false));

        $schedule = DistributionSchedule::query()->where('user_id', $operator->id)->sole();
        $this->assertSame($own->id, $schedule->showroom_id);
        $this->assertTrue($schedule->participates);
        $this->assertSame([true, true, true, true, true, false, false], $schedule->weekdays());
        $this->assertSame(['2026-10-24'], $schedule->exceptions()->pluck('date')->all());
        $this->assertFalse($outsider->showrooms()->whereKey($own->id)->exists(), 'A schedule never grants access.');
        $audit = AuditLog::query()->where('event', 'distribution.schedule_updated')->sole();
        $this->assertSame('distribution_schedule', $audit->subject_type);
    }

    public function test_copying_a_schedule_needs_both_showrooms_and_stays_independent(): void
    {
        $north = $this->showroom();
        $south = $this->showroom();
        $operator = $this->operator([$north, $south], 'Оператор');
        $this->enroll($operator, $north, [1, 2], ['2026-10-24' => true]);
        $southOnly = $this->employeeWith('dist_senior', [P::ORDERS_VIEW, P::SCHEDULES_VIEW, P::SCHEDULES_MANAGE], [$south]);
        $both = $this->employeeWith('dist_senior', [P::ORDERS_VIEW, P::SCHEDULES_VIEW, P::SCHEDULES_MANAGE], [$north, $south]);

        $this->actingAs($southOnly)->get(route('distribution.schedules', ['showroom' => $south->id]))
            ->assertInertia(fn (Assert $page) => $page->where('members.data', fn ($rows) => collect($rows)->firstWhere('id', $operator->id)['copy_from'] === []));
        $this->actingAs($southOnly)->post(route('distribution.schedules.copy', [$south->id, $operator->id]), ['from_showroom_id' => $north->id, 'lock_version' => 0])
            ->assertSessionHasErrors('from_showroom_id');
        $this->assertFalse(DistributionSchedule::query()->where('showroom_id', $south->id)->exists());

        $this->actingAs($both)->post(route('distribution.schedules.copy', [$south->id, $operator->id]), ['from_showroom_id' => $north->id, 'lock_version' => 0])
            ->assertSessionHasNoErrors();

        $copy = DistributionSchedule::query()->where('showroom_id', $south->id)->sole();
        $this->assertFalse($copy->participates, 'Participation of the target showroom is not copied.');
        $this->assertSame([true, true, false, false, false, false, false], $copy->weekdays());
        $this->assertSame(['2026-10-24'], $copy->exceptions()->pluck('date')->all());

        $this->actingAs($both)->put(route('distribution.schedules.update', [$north->id, $operator->id]), [
            'participates' => true,
            'weekdays' => [false, false, false, false, false, false, true],
            'exceptions' => [],
            'lock_version' => 0,
        ])->assertSessionHasNoErrors();

        $copy->refresh();
        $this->assertSame([true, true, false, false, false, false, false], $copy->weekdays());
        $this->assertSame(1, $copy->exceptions()->count());
    }

    public function test_manual_assignment_ignores_schedules_and_conflicts_with_automatic_assignment(): void
    {
        $showroom = $this->showroom();
        $operator = $this->operator([$showroom], 'Оператор');
        $colleague = $this->operator([$showroom], 'Коллега без графика');
        $order = $this->callbackOrder($showroom, '2026-10-19 10:00');
        $this->distribute($order);
        $staleForm = $this->updatePayload($order->fresh(), ['responsible_id' => $colleague->id]);

        $this->enroll($operator, $showroom, self::EVERY_DAY);
        $this->artisan('orders:distribute')->assertSuccessful();
        $this->assertSame($operator->id, $order->fresh()->responsible_id);

        $this->actingAs($this->admin)->put(route('orders.update', $order->id), $staleForm)->assertSessionHasErrors('lock_version');
        $this->assertSame($operator->id, $order->fresh()->responsible_id);

        $manual = $this->callbackOrder($showroom, '2026-10-19 10:05');
        $this->actingAs($this->admin)->put(route('orders.update', $manual->id), $this->updatePayload($manual->fresh(), ['responsible_id' => $colleague->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($colleague->id, $manual->fresh()->responsible_id);
        $this->assertNull($manual->fresh()->distribution_state);
    }

    private function showroom(): Showroom
    {
        return Showroom::factory()->create([
            'timezone' => 'Europe/Moscow',
            'business_day_boundary_enabled' => true,
            'business_day_boundary' => '19:00',
        ]);
    }

    /**
     * @param  list<Showroom>  $showrooms
     */
    private function operator(array $showrooms, string $name = 'Оператор'): User
    {
        return $this->employeeWith('dist_operator', [P::ORDERS_VIEW, P::ORDERS_UPDATE], $showrooms, ['name' => $name]);
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
     * @param  list<int>  $days  ISO weekdays
     * @param  array<string, bool>  $exceptions
     */
    private function enroll(User $user, Showroom $showroom, array $days, array $exceptions = []): void
    {
        $schedule = new DistributionSchedule;
        $schedule->forceFill(['user_id' => $user->id, 'showroom_id' => $showroom->id, 'participates' => true]);

        foreach (DistributionSchedule::WEEKDAYS as $day => $column) {
            $schedule->setAttribute($column, in_array($day, $days, true));
        }

        $schedule->save();

        foreach ($exceptions as $date => $works) {
            DistributionScheduleException::create(['distribution_schedule_id' => $schedule->id, 'date' => $date, 'works' => $works]);
        }
    }

    private function callbackOrder(Showroom $showroom, string $moscowTime, ?string $phone = null, ?int $responsibleId = null, mixed $channelId = null): Order
    {
        $this->travelTo(CarbonImmutable::parse($moscowTime, 'Europe/Moscow'));
        $source = $this->sources[$showroom->id] ??= $this->makeSource($showroom);
        $phone ??= sprintf('+7 999 200-%02d-%02d', intdiv(++$this->phoneSequence, 100), $this->phoneSequence % 100);

        $this->actingAs($this->admin)->post(route('orders.store'), [
            'showroom_id' => $source->showroom_id,
            'source_id' => $source->id,
            'contact_channel_id' => $channelId ?? ContactChannel::query()->where('code', ContactChannel::CALLBACK)->value('id'),
            'contact_name' => 'Клиент',
            'phones' => [['number' => $phone, 'name' => null]],
            'car_interest' => null,
            'responsible_id' => $responsibleId,
        ])->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }

    /**
     * @return int|null the assigned responsible
     */
    private function distribute(Order $order): ?int
    {
        app(CallbackDistributor::class)->request($order->fresh(), $this->admin);

        return $order->fresh()->responsible_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayload(Order $order, array $overrides = []): array
    {
        return [
            'contact_channel_id' => $order->contact_channel_id,
            'contact_name' => $order->contact_name,
            'phones' => array_map(fn (array $phone) => ['number' => $phone['phone'], 'name' => $phone['name']], $order->contactPhones()),
            'car_interest' => null,
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
