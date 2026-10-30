<?php

namespace Tests\Feature\Distribution;

use App\Enums\DistributionState;
use App\Models\AuditLog;
use App\Models\ContactChannel;
use App\Models\DistributionSchedule;
use App\Models\Order;
use App\Models\OrderTransfer;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\User;
use App\Services\OrderManager;
use App\Support\PermissionCatalog as P;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

/**
 * Uses truncation instead of a wrapping transaction: separate PHP processes must
 * see committed rows.
 */
class DistributionConcurrencyTest extends TestCase
{
    use BuildsOrganization;
    use BuildsSources;
    use DatabaseTruncation;

    private User $admin;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_two_attempts_on_one_order_assign_it_once(): void
    {
        [$showroom, $orders] = $this->prepare(1, 2);

        $results = $this->race(
            fn () => Showroom::query()->lockForUpdate()->findOrFail($showroom->id),
            [['distribute-order.php', $orders[0]->id], ['distribute-order.php', $orders[0]->id]],
        );

        $this->assertCount(1, array_filter($results, fn (string $result) => $result !== 'ok:none'));
        $order = $orders[0]->fresh();
        $this->assertNotNull($order->responsible_id);
        $this->assertNull($order->distribution_state);
        $this->assertSame(1, $order->lock_version);
        $this->assertSame(1, AuditLog::query()->where('event', 'order.responsible_changed')->where('subject_id', $order->id)->count());
    }

    public function test_concurrent_orders_of_one_showroom_see_each_others_load(): void
    {
        [$showroom, $orders, $users] = $this->prepare(2, 2);

        $results = $this->race(
            fn () => Showroom::query()->lockForUpdate()->findOrFail($showroom->id),
            [['distribute-order.php', $orders[0]->id], ['distribute-order.php', $orders[1]->id]],
        );

        $this->assertEqualsCanonicalizing($users->pluck('id')->map(fn (int $id) => "ok:{$id}")->all(), $results);
        $this->assertEqualsCanonicalizing($users->pluck('id')->all(), Order::query()->pluck('responsible_id')->all());
    }

    public function test_take_and_manual_assignment_of_one_order_do_not_overwrite_each_other(): void
    {
        [, $orders, $users] = $this->prepare(1, 2, pending: false);
        $order = $orders[0];

        $results = $this->race(
            fn () => Order::query()->lockForUpdate()->findOrFail($order->id),
            [['take-order.php', 'take', $users[0]->id, $order->id], ['take-order.php', 'assign', $this->admin->id, $order->id, $users[1]->id, $order->lock_version]],
        );

        $this->assertEqualsCanonicalizing(['ok', 'rejected'], $results);
        $order->refresh();
        $this->assertContains($order->responsible_id, $users->pluck('id')->all());
        $this->assertSame(1, $order->lock_version);
        $this->assertSame(1, AuditLog::query()->where('subject_type', 'order')->where('subject_id', $order->id)->where('event', 'like', 'order.%')->where('event', '!=', 'order.created')->count());
    }

    public function test_concurrent_transfers_reuse_one_internal_source_and_one_operation_creates_one_order(): void
    {
        [$from, $orders] = $this->prepare(3, 0, pending: false);
        $to = Showroom::factory()->create();
        $repeated = (string) Str::uuid();

        $results = $this->race(
            fn () => Showroom::query()->lockForUpdate()->findOrFail($to->id),
            [
                ['transfer-order.php', $this->admin->id, $orders[0]->id, $to->id, (string) Str::uuid(), 0],
                ['transfer-order.php', $this->admin->id, $orders[1]->id, $to->id, $repeated, 0],
                ['transfer-order.php', $this->admin->id, $orders[1]->id, $to->id, $repeated, 0],
            ],
        );

        $this->assertCount(3, array_filter($results, fn (string $result) => str_starts_with($result, 'ok:')));
        $this->assertSame($results[1], $results[2]);
        $this->assertSame(2, OrderTransfer::query()->count());
        $this->assertSame(1, Source::query()->where('showroom_id', $to->id)->where('transfer_from_showroom_id', $from->id)->count());
        $this->assertSame(2, Order::query()->where('showroom_id', $to->id)->count());
        $this->assertSame(1, $orders[1]->fresh()->lock_version);
    }

    /**
     * @return array{0: Showroom, 1: list<Order>, 2: Collection<int, User>}
     */
    private function prepare(int $orderCount, int $operatorCount, bool $pending = true): array
    {
        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);

        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $this->admin = $this->administrator();
        Role::query()->firstOrCreate(['name' => 'dist_operator', 'guard_name' => 'web'], ['label' => 'dist_operator'])->syncPermissions([P::ORDERS_VIEW, P::ORDERS_UPDATE]);

        $users = collect($operatorCount > 0 ? range(1, $operatorCount) : [])->map(function () use ($showroom) {
            $user = $this->employee('dist_operator', [$showroom]);
            $schedule = new DistributionSchedule;
            $schedule->forceFill(['user_id' => $user->id, 'showroom_id' => $showroom->id, 'participates' => true]);

            foreach (DistributionSchedule::WEEKDAYS as $column) {
                $schedule->setAttribute($column, true);
            }

            $schedule->save();

            return $user;
        })->values();

        $channel = ContactChannel::query()->where('code', ContactChannel::CALLBACK)->value('id');
        $orders = array_map(function (int $index) use ($source, $channel, $pending) {
            $order = app(OrderManager::class)->create($this->admin, [
                'showroom_id' => $source->showroom_id,
                'source_id' => $source->id,
                'contact_channel_id' => $channel,
                'contact_name' => 'Клиент',
                'phones' => [sprintf('7999300%04d', $index)],
                'phone_names' => [],
                'car_interest' => null,
                'responsible_id' => null,
            ]);

            if ($pending) {
                $order->forceFill(['distribution_state' => DistributionState::Pending])->saveQuietly();
            }

            return $order->fresh();
        }, range(1, $orderCount));

        return [$showroom, $orders, $users];
    }

    /**
     * Holds the given lock while every process loads and blocks on it, then releases it.
     *
     * @param  list<list<int|string>>  $commands  script name and its arguments
     * @return list<string> the last output line of each process
     */
    private function race(callable $lock, array $commands): array
    {
        $php = (new PhpExecutableFinder)->find(false);
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];
        $processes = array_map(function (array $command) use ($php, $env) {
            $script = array_shift($command);

            return new Process([$php, base_path("tests/Support/{$script}"), ...array_map('strval', $command)], base_path(), $env, null, 60);
        }, $commands);

        DB::beginTransaction();
        $lock();

        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            $process->waitUntil(fn (string $type, string $output) => str_contains($output, 'ready'));
        }

        usleep(1_500_000);
        $this->assertCount(count($processes), array_filter($processes, fn (Process $process) => $process->isRunning()), 'Every process must wait for the lock. '.implode(' | ', array_map(fn (Process $process) => $process->getOutput().$process->getErrorOutput(), $processes)));

        DB::commit();

        return array_map(function (Process $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
            $this->assertMatchesRegularExpression('/^(ok(:\d+|:none)?|rejected)$/', end($lines), $process->getOutput());

            return end($lines);
        }, $processes);
    }
}
