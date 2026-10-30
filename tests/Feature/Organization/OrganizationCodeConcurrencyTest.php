<?php

namespace Tests\Feature\Organization;

use App\Models\Department;
use App\Models\Showroom;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Uses truncation instead of a wrapping transaction: separate PHP processes must
 * see committed rows.
 */
class OrganizationCodeConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_concurrent_showrooms_with_same_name_get_distinct_codes(): void
    {
        $codes = $this->race(
            fn () => Showroom::factory()->create(['code' => 'moskva', 'name' => 'Москва']),
            [['showroom', 'Москва'], ['showroom', 'Москва']],
        );

        $this->assertEqualsCanonicalizing(['moskva-2', 'moskva-3'], $codes);
        $this->assertEqualsCanonicalizing(['moskva', 'moskva-2', 'moskva-3'], Showroom::query()->pluck('code')->all());
    }

    public function test_concurrent_shared_departments_with_same_name_get_distinct_codes(): void
    {
        $showroom = Showroom::factory()->create();

        $codes = $this->race(
            fn () => Department::factory()->shared()->create(['code' => 'prodazhi', 'name' => 'Продажи']),
            [['department', 'Продажи', 'shared'], ['department', 'Продажи', 'shared'], ['department', 'Продажи', (string) $showroom->id]],
        );

        $this->assertEqualsCanonicalizing(['prodazhi-2', 'prodazhi-3', 'prodazhi'], $codes);
        $this->assertEqualsCanonicalizing(
            ['prodazhi', 'prodazhi-2', 'prodazhi-3'],
            Department::query()->whereNull('showroom_id')->pluck('code')->all(),
        );
        $this->assertSame(['prodazhi'], $showroom->departments()->pluck('code')->all());
    }

    /**
     * $holder inserts a row with the shared base code inside an open transaction. The
     * processes cannot see it when picking a code, so each insert blocks on the
     * unique index; after commit they must recover through the constraint alone.
     *
     * @param  callable(): mixed  $holder
     * @param  list<list<string>>  $jobs
     * @return list<string> generated codes in job order
     */
    private function race(callable $holder, array $jobs): array
    {
        $php = (new PhpExecutableFinder)->find(false);
        $script = base_path('tests/Support/create-organization-unit.php');
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];

        $processes = array_map(fn (array $job) => new Process([$php, $script, ...$job], base_path(), $env, null, 60), $jobs);

        DB::beginTransaction();
        $holder();

        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            $process->waitUntil(fn (string $type, string $output) => str_contains($output, 'ready'));
        }

        usleep(1_500_000);
        $blocked = array_filter($processes, fn (Process $process) => $process->isRunning());
        $this->assertGreaterThanOrEqual(2, count($blocked), 'Processes must wait on the unique index held by this transaction.');

        DB::commit();

        return array_map(function (Process $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertMatchesRegularExpression('/^ok:([a-z0-9_-]+)$/m', $process->getOutput(), $process->getOutput());
            preg_match('/^ok:([a-z0-9_-]+)$/m', $process->getOutput(), $match);

            return $match[1];
        }, $processes);
    }
}
