<?php

namespace Tests\Feature\Orders;

use App\Models\Client;
use App\Models\Order;
use App\Models\Showroom;
use App\Services\OrderManager;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

/**
 * Uses truncation instead of a wrapping transaction: separate PHP processes must
 * see committed rows.
 */
class OrderRepeatConcurrencyTest extends TestCase
{
    use BuildsOrganization;
    use BuildsSources;
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_concurrent_orders_of_one_client_get_distinct_repeat_numbers(): void
    {
        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);

        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $channel = $source->channels()->value('contact_channels.id');
        $admin = $this->administrator();
        $first = app(OrderManager::class)->create($admin, [
            'showroom_id' => $showroom->id,
            'source_id' => $source->id,
            'contact_channel_id' => $channel,
            'contact_name' => 'Клиент',
            'phones' => ['79515346783'],
            'phone_names' => [],
            'car_interest' => null,
        ]);

        $php = (new PhpExecutableFinder)->find(false);
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];
        $processes = array_map(
            fn () => new Process([$php, base_path('tests/Support/create-order.php'), (string) $admin->id, (string) $source->id, (string) $channel, '79515346783'], base_path(), $env, null, 60),
            [1, 2],
        );

        DB::beginTransaction();
        Client::query()->lockForUpdate()->findOrFail($first->client_id);

        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            $process->waitUntil(fn (string $type, string $output) => str_contains($output, 'ready'));
        }

        usleep(1_500_000);
        $this->assertCount(2, array_filter($processes, fn (Process $process) => $process->isRunning()), 'Both processes must wait for the client lock. '.implode(' | ', array_map(fn (Process $process) => $process->getOutput().$process->getErrorOutput(), $processes)));

        DB::commit();

        $repeats = array_map(function (Process $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertMatchesRegularExpression('/^ok:(\d+)$/m', $process->getOutput(), $process->getOutput());
            preg_match('/^ok:(\d+)$/m', $process->getOutput(), $match);

            return (int) $match[1];
        }, $processes);

        $this->assertEqualsCanonicalizing([1, 2], $repeats);
        $this->assertSame(1, Client::query()->count());
        $this->assertEqualsCanonicalizing([0, 1, 2], Order::query()->pluck('repeat_number')->all());
    }
}
