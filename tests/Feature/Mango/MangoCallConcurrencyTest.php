<?php

namespace Tests\Feature\Mango;

use App\Models\AuditLog;
use App\Models\Call;
use App\Models\MangoEvent;
use App\Models\Order;
use App\Models\Showroom;
use App\Services\OrderManager;
use Carbon\CarbonImmutable;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Mango\Concerns\BuildsMangoCalls;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

/**
 * Uses truncation instead of a wrapping transaction: separate PHP processes must
 * see committed rows.
 */
class MangoCallConcurrencyTest extends TestCase
{
    use BuildsMangoCalls;
    use BuildsOrganization;
    use BuildsSources;
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_realtime_event_and_summary_processed_at_once_give_one_call_and_one_order(): void
    {
        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
        $showroom = Showroom::factory()->create(['timezone' => 'Europe/Moscow']);
        $connection = $this->mangoConnection($showroom);
        $this->bindLine($connection, $this->makeSource($showroom), self::LINE);
        $operator = $this->mangoEmployee('Оператор', [$showroom]);
        $this->extension($connection, '123', $operator);

        Queue::fake();
        $this->mangoEvent($connection, 'call', $this->routedCall('entry-race')[3])->assertOk();
        $this->mangoEvent($connection, 'summary', $this->summary('entry-race'))->assertOk();
        $events = MangoEvent::query()->orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $events);

        $php = (new PhpExecutableFinder)->find(false);
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ];
        $processes = array_map(
            fn (int $event) => new Process([$php, base_path('tests/Support/process-mango-event.php'), (string) $event], base_path(), $env, null, 60),
            $events,
        );

        // The uncommitted row holds the unique key, so both processes wait at the first insert.
        DB::beginTransaction();
        DB::table('calls')->insert([
            'integration_connection_id' => $connection->id,
            'entry_id' => 'entry-race',
            'match_state' => 'pending',
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);

        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            $process->waitUntil(fn (string $type, string $output) => str_contains($output, 'ready'));
        }

        usleep(1_500_000);
        $this->assertCount(2, array_filter($processes, fn (Process $process) => $process->isRunning()), 'Both processes must wait for the call row. '.implode(' | ', array_map(fn (Process $process) => $process->getOutput().$process->getErrorOutput(), $processes)));

        DB::commit();

        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('ok:processed', $process->getOutput(), $process->getOutput());
        }

        $call = Call::query()->sole();
        $order = Order::query()->sole();
        $this->assertSame($order->id, $call->order_id);
        $this->assertSame($operator->id, $order->responsible_id);
        $this->assertSame(30, $call->talk_seconds);
    }

    public function test_manual_assignment_made_while_the_call_is_processed_is_not_replaced(): void
    {
        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
        $showroom = Showroom::factory()->create(['timezone' => 'Europe/Moscow']);
        $source = $this->makeSource($showroom);
        $connection = $this->mangoConnection($showroom);
        $this->bindLine($connection, $source, self::LINE);
        $operator = $this->mangoEmployee('Оператор', [$showroom]);
        $manual = $this->mangoEmployee('Назначен вручную', [$showroom]);
        $this->extension($connection, '123', $operator);
        $order = app(OrderManager::class)->create($this->administrator(), [
            'showroom_id' => $showroom->id,
            'source_id' => $source->id,
            'contact_channel_id' => $source->channels()->where('code', 'callback-request')->value('contact_channels.id'),
            'contact_name' => 'Клиент',
            'phones' => [self::CLIENT],
            'phone_names' => [],
            'car_interest' => null,
            'responsible_id' => null,
        ]);

        Queue::fake();
        $this->mangoEvent($connection, 'summary', $this->summary('entry-manual-race'))->assertOk();
        $process = $this->eventProcess(MangoEvent::query()->sole()->id);

        // The manual assignment holds the order row: the call waits for it at the assignment.
        DB::beginTransaction();
        Order::query()->lockForUpdate()->findOrFail($order->id);
        Order::query()->whereKey($order->id)->update(['responsible_id' => $manual->id]);

        $process->start();
        $process->waitUntil(fn (string $type, string $output) => str_contains($output, 'ready'));
        usleep(1_500_000);
        $this->assertTrue($process->isRunning(), 'The call must wait for the order lock. '.$process->getOutput().$process->getErrorOutput());

        DB::commit();
        $process->wait();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('ok:processed', $process->getOutput());

        $call = Call::query()->sole();
        $this->assertSame($manual->id, $order->fresh()->responsible_id);
        $this->assertSame([$order->id, $operator->id, null], [$call->order_id, $call->answered_by_id, $call->assigned_user_id]);
        $this->assertNotNull($call->assignment_decided_at);
        $this->assertSame(0, AuditLog::query()->where('event', 'order.responsible_changed')->count());
    }

    private function eventProcess(int $eventId): Process
    {
        return new Process([(new PhpExecutableFinder)->find(false), base_path('tests/Support/process-mango-event.php'), (string) $eventId], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ], null, 60);
    }
}
