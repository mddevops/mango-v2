<?php

namespace Tests\Feature\Organization;

use App\Models\Role;
use App\Models\User;
use App\Services\AdministratorGuard;
use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsOrganization;
use Tests\TestCase;

/**
 * Uses truncation instead of a wrapping transaction: the concurrency case needs
 * committed rows visible to separate PHP processes.
 */
class LastAdministratorTest extends TestCase
{
    use BuildsOrganization;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    /**
     * A non-administrator role holding every permission can manage administrators,
     * which is the only way the last one could be removed by someone else.
     */
    private function superuser()
    {
        $role = Role::query()->create(['name' => 'superuser', 'label' => 'Суперпользователь', 'guard_name' => 'web']);
        $role->syncPermissions(PermissionCatalog::all());

        return $this->employee('superuser', [], ['has_global_access' => true]);
    }

    public function test_last_administrator_cannot_be_deactivated(): void
    {
        $admin = $this->administrator();
        $superuser = $this->superuser();

        $this->actingAs($superuser)
            ->patch(route('users.status', $admin), ['active' => false, 'lock_version' => $admin->lock_version])
            ->assertSessionHasErrors('active');

        $this->assertTrue($admin->fresh()->active);
        $this->assertSame(1, app(AdministratorGuard::class)->count());
    }

    public function test_last_administrator_cannot_lose_role_or_global_access(): void
    {
        $admin = $this->administrator();
        $superuser = $this->superuser();

        $this->actingAs($superuser)
            ->put(route('users.update', $admin), [...$this->updatePayload($admin), 'role_ids' => []])
            ->assertSessionHasErrors('role_ids');

        $this->actingAs($superuser)
            ->patch(route('users.global-access', $admin), ['active' => false, 'lock_version' => $admin->fresh()->lock_version])
            ->assertSessionHasErrors();

        $admin->refresh();
        $this->assertTrue($admin->hasRole(RoleCatalog::ADMINISTRATOR));
        $this->assertTrue($admin->has_global_access);
    }

    public function test_one_of_two_administrators_can_be_deactivated(): void
    {
        $first = $this->administrator();
        $second = $this->administrator();

        $this->actingAs($first)
            ->patch(route('users.status', $second), ['active' => false, 'lock_version' => $second->lock_version])
            ->assertSessionHasNoErrors();

        $this->assertFalse($second->fresh()->active);
        $this->assertSame(1, app(AdministratorGuard::class)->count());
    }

    public function test_concurrent_mutual_deactivation_leaves_one_administrator(): void
    {
        $first = $this->administrator();
        $second = $this->administrator();

        $php = (new PhpExecutableFinder)->find(false);
        $script = base_path('tests/Support/deactivate-user.php');
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];

        $processes = [
            new Process([$php, $script, (string) $first->id, (string) $second->id], base_path(), $env, null, 60),
            new Process([$php, $script, (string) $second->id, (string) $first->id], base_path(), $env, null, 60),
        ];

        DB::beginTransaction();
        Role::query()->where('name', RoleCatalog::ADMINISTRATOR)->lockForUpdate()->first();

        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            $process->waitUntil(fn (string $type, string $output) => str_contains($output, 'ready'));
        }

        // Both processes are now blocked on the administrator role row held by this transaction.
        usleep(1_500_000);
        foreach ($processes as $process) {
            $this->assertTrue($process->isRunning(), 'Process finished without waiting for the lock: '.$process->getOutput().$process->getErrorOutput());
        }

        DB::commit();

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $results[] = str_contains($process->getOutput(), "ok\n") ? 'ok' : (str_contains($process->getOutput(), 'rejected') ? 'rejected' : 'unknown');
        }

        sort($results);
        $this->assertSame(['ok', 'rejected'], $results);
        $this->assertSame(1, app(AdministratorGuard::class)->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function concurrentChangePairs(): array
    {
        return [
            'deactivation and role removal' => ['deactivate', 'remove-role'],
            'role removal and global access revocation' => ['remove-role', 'revoke-global'],
            'deactivation and global access revocation' => ['deactivate', 'revoke-global'],
        ];
    }

    /**
     * Two different changes, each removing one of the two administrators, start in
     * separate processes while the guard lock is held; both must be waiting on it
     * before it is released. Exactly one may win, and the loser must leave its
     * target untouched.
     */
    #[DataProvider('concurrentChangePairs')]
    public function test_concurrent_changes_of_different_types_leave_one_full_administrator(string $firstAction, string $secondAction): void
    {
        $first = $this->administrator();
        $second = $this->administrator();
        $editor = $this->superuser();

        $results = $this->race([
            [$firstAction, $editor, $first],
            [$secondAction, $editor, $second],
        ]);

        $this->assertEqualsCanonicalizing(['ok', 'rejected'], $results);

        foreach ([$first, $second] as $index => $target) {
            $this->assertSame(
                $results[$index] === 'rejected',
                $this->isFullAdministrator($target->fresh()),
                "Target #{$index} ({$results[$index]}) is in an unexpected state",
            );
        }

        $guard = app(AdministratorGuard::class);
        $this->assertSame(1, $guard->count());
        $this->assertFalse($this->isFullAdministrator($editor->fresh()));
    }

    /**
     * Matches AdministratorGuard::count(): active, global access and the administrator role.
     */
    private function isFullAdministrator(User $user): bool
    {
        return $user->active
            && $user->has_global_access
            && $user->hasRole(RoleCatalog::ADMINISTRATOR);
    }

    /**
     * @param  list<array{string, User, User}>  $jobs  [action, editor, target]
     * @return list<string> "ok" or "rejected" per job, in job order
     */
    private function race(array $jobs): array
    {
        $php = (new PhpExecutableFinder)->find(false);
        $script = base_path('tests/Support/change-administrator.php');
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];

        $processes = array_map(
            fn (array $job) => new Process(
                [$php, $script, $job[0], (string) $job[1]->id, (string) $job[2]->id],
                base_path(),
                $env,
                null,
                60,
            ),
            $jobs,
        );

        DB::beginTransaction();
        Role::query()->where('name', RoleCatalog::ADMINISTRATOR)->lockForUpdate()->first();

        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            $process->waitUntil(fn (string $type, string $output) => str_contains($output, 'ready'));
        }

        usleep(1_500_000);
        foreach ($processes as $process) {
            $this->assertTrue($process->isRunning(), 'Process finished without waiting for the lock: '.$process->getOutput().$process->getErrorOutput());
        }

        DB::commit();

        return array_map(function (Process $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $output = $process->getOutput();

            return match (true) {
                str_contains($output, "ok\n") => 'ok',
                str_contains($output, "rejected\n") => 'rejected',
                default => $this->fail('Unexpected process output: '.$output),
            };
        }, $processes);
    }
}
