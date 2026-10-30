<?php

namespace Tests\Feature\Organization;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_are_idempotent_and_keep_ui_changes(): void
    {
        $this->seed(DatabaseSeeder::class);

        $operator = Role::query()->where('name', 'operator')->firstOrFail();
        $operator->forceFill(['label' => 'Оператор КЦ'])->save();
        $operator->givePermissionTo(PermissionCatalog::USERS_VIEW);
        Department::query()->where('code', 'call_center')->update(['name' => 'Колл-центр Москва']);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(count(RoleCatalog::defaults()), Role::query()->count());
        $this->assertSame(count(PermissionCatalog::all()), Permission::query()->count());
        $this->assertSame(1, Department::query()->whereNull('showroom_id')->where('code', 'call_center')->count());
        $this->assertSame('Колл-центр Москва', Department::query()->where('code', 'call_center')->value('name'));

        $operator->refresh();
        $this->assertSame('Оператор КЦ', $operator->label);
        $this->assertEqualsCanonicalizing(['workspace.view', 'users.view'], $operator->permissions->pluck('name')->all());

        $administrator = Role::query()->where('name', RoleCatalog::ADMINISTRATOR)->firstOrFail();
        $this->assertTrue($administrator->is_system);
        $this->assertCount(count(PermissionCatalog::all()), $administrator->permissions);

        $this->assertSame(0, User::query()->count(), 'Seeders must not create accounts with fixed passwords.');
        $this->assertSame(
            DB::table('role_has_permissions')->count(),
            DB::table('role_has_permissions')->distinct()->count(DB::raw('concat(role_id, "-", permission_id)')),
        );
    }

    public function test_create_admin_command_asks_password_secretly_and_creates_global_admin(): void
    {
        $this->artisan('app:create-admin', ['--name' => 'Главный', '--email' => 'Root@Example.com'])
            ->expectsQuestion('Пароль (ввод скрыт)', 'Strong-Password-2026')
            ->expectsQuestion('Повторите пароль', 'Strong-Password-2026')
            ->assertSuccessful();

        $user = User::query()->where('email', 'root@example.com')->firstOrFail();
        $this->assertTrue($user->active);
        $this->assertTrue($user->has_global_access);
        $this->assertTrue($user->hasRole(RoleCatalog::ADMINISTRATOR));
        $this->assertTrue(Hash::check('Strong-Password-2026', $user->password));
        $this->assertStringNotContainsString('Strong-Password-2026', json_encode(DB::table('audit_logs')->get()));
    }

    public function test_create_admin_command_rejects_weak_or_mismatched_password(): void
    {
        $this->artisan('app:create-admin', ['--name' => 'Слабый', '--email' => 'weak@example.com'])
            ->expectsQuestion('Пароль (ввод скрыт)', 'short')
            ->expectsQuestion('Повторите пароль', 'other')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'weak@example.com']);
    }

    public function test_promote_admin_changes_only_the_requested_account(): void
    {
        $target = User::factory()->create(['email' => 'boss@example.com']);
        $bystander = User::factory()->create();

        $this->artisan('app:promote-admin', ['email' => 'boss@example.com'])
            ->expectsConfirmation('Выдать «'.$target->name.'» (boss@example.com) роль администратора и глобальный доступ?', 'yes')
            ->assertSuccessful();

        $target->refresh();
        $bystander->refresh();
        $this->assertTrue($target->hasRole(RoleCatalog::ADMINISTRATOR));
        $this->assertTrue($target->has_global_access);
        $this->assertFalse($bystander->hasRole(RoleCatalog::ADMINISTRATOR));
        $this->assertFalse($bystander->has_global_access);
    }

    public function test_promote_admin_can_be_cancelled(): void
    {
        $target = User::factory()->create(['email' => 'maybe@example.com']);

        $this->artisan('app:promote-admin', ['email' => 'maybe@example.com'])
            ->expectsConfirmation('Выдать «'.$target->name.'» (maybe@example.com) роль администратора и глобальный доступ?', 'no')
            ->assertFailed();

        $this->assertFalse($target->fresh()->hasRole(RoleCatalog::ADMINISTRATOR));
    }
}
