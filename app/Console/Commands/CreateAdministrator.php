<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\RoleCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdministrator extends Command
{
    protected $signature = 'app:create-admin
        {--name= : Имя администратора}
        {--email= : Email администратора}';

    protected $description = 'Создать нового администратора с глобальным доступом (пароль вводится скрыто)';

    public function handle(AuditLogger $audit): int
    {
        $name = $this->option('name') ?: $this->ask('Имя');
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Email'))));
        $password = (string) $this->secret('Пароль (ввод скрыт)');
        $confirmation = (string) $this->secret('Повторите пароль');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', Password::min(12)->letters()->mixedCase()->numbers(), 'confirmed'],
            ],
            [],
            ['name' => 'имя', 'email' => 'email', 'password' => 'пароль'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $user = DB::transaction(function () use ($name, $email, $password, $audit) {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'active' => true,
                'has_global_access' => true,
                'email_verified_at' => now(),
            ])->save();

            $user->assignRole(Role::findByName(RoleCatalog::ADMINISTRATOR, 'web'));

            $audit->as(null, fn () => $audit->record(
                'user.created',
                'user',
                $user->id,
                $user->name,
                'Создан администратор через консоль',
                ['name' => $user->name, 'email' => $user->email, 'roles' => ['Администратор']],
            ));
            $audit->as(null, fn () => $audit->record(
                'user.global_access_granted',
                'user',
                $user->id,
                $user->name,
                'Выдан глобальный доступ ко всем салонам',
                ['has_global_access' => [false, true]],
            ));

            return $user;
        });

        $this->info("Администратор {$user->email} создан.");

        return self::SUCCESS;
    }
}
