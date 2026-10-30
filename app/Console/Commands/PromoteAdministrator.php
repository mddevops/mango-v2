<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\RoleCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PromoteAdministrator extends Command
{
    protected $signature = 'app:promote-admin
        {email : Email существующей учётной записи}
        {--force : Не спрашивать подтверждение}';

    protected $description = 'Явно повысить одну существующую учётную запись до администратора с глобальным доступом';

    public function handle(AuditLogger $audit): int
    {
        $argument = $this->argument('email');
        $email = mb_strtolower(trim(is_string($argument) ? $argument : ''));

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Учётная запись {$email} не найдена.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Выдать «{$user->name}» ({$user->email}) роль администратора и глобальный доступ?")) {
            $this->warn('Отменено.');

            return self::FAILURE;
        }

        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        DB::transaction(function () use ($user, $audit) {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $changes = [];

            if (! $locked->active) {
                $changes['active'] = [false, true];
            }

            if (! $locked->has_global_access) {
                $changes['has_global_access'] = [false, true];
            }

            $locked->forceFill(['active' => true, 'has_global_access' => true])->bumpLockVersion()->save();

            if (! $locked->hasRole(RoleCatalog::ADMINISTRATOR)) {
                $locked->assignRole(Role::findByName(RoleCatalog::ADMINISTRATOR, 'web'));
                $audit->as(null, fn () => $audit->record('user.roles_changed', 'user', $locked->id, $locked->name, 'Назначена роль через консоль', [
                    'added' => ['Администратор'],
                    'removed' => [],
                ]));
            }

            if (isset($changes['has_global_access'])) {
                $audit->as(null, fn () => $audit->record('user.global_access_granted', 'user', $locked->id, $locked->name, 'Выдан глобальный доступ ко всем салонам', [
                    'has_global_access' => [false, true],
                ]));
            }

            if (isset($changes['active'])) {
                $audit->as(null, fn () => $audit->record('user.activated', 'user', $locked->id, $locked->name, 'Сотрудник активирован через консоль', [
                    'active' => [false, true],
                ]));
            }
        });

        $this->info("{$user->email} теперь администратор с глобальным доступом.");

        return self::SUCCESS;
    }
}
