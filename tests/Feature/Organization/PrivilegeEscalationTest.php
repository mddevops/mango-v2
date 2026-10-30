<?php

namespace Tests\Feature\Organization;

use App\Models\Role;
use App\Models\Showroom;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrganization;
use Tests\TestCase;

class PrivilegeEscalationTest extends TestCase
{
    use BuildsOrganization;
    use RefreshDatabase;

    private Showroom $showroom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->showroom = Showroom::factory()->create();
    }

    public function test_employee_cannot_change_own_roles_showrooms_or_departments(): void
    {
        $manager = $this->employee('showroom_manager', [$this->showroom]);
        $other = Showroom::factory()->create();

        $this->actingAs($manager)
            ->put(route('users.update', $manager), [
                ...$this->updatePayload($manager),
                'role_ids' => [$this->role('showroom_manager')->id, $this->role('operator')->id],
            ])
            ->assertSessionHasErrors('role_ids');

        $this->actingAs($manager)
            ->put(route('users.update', $manager), [
                ...$this->updatePayload($manager),
                'showroom_ids' => [$this->showroom->id, $other->id],
            ])
            ->assertSessionHasErrors();

        $manager->refresh();
        $this->assertSame(['showroom_manager'], $manager->getRoleNames()->all());
        $this->assertSame([$this->showroom->id], $manager->showrooms()->pluck('showrooms.id')->all());
    }

    public function test_administrator_cannot_change_own_roles_either(): void
    {
        $admin = $this->administrator();
        $this->administrator();

        $this->actingAs($admin)
            ->put(route('users.update', $admin), [...$this->updatePayload($admin), 'role_ids' => []])
            ->assertSessionHasErrors('role_ids');

        $this->assertTrue($admin->fresh()->hasRole('administrator'));
    }

    public function test_cannot_change_own_status_or_global_access(): void
    {
        $admin = $this->administrator();
        $this->administrator();

        $this->actingAs($admin)
            ->patch(route('users.status', $admin), ['active' => false, 'lock_version' => $admin->lock_version])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('users.global-access', $admin), ['active' => false, 'lock_version' => $admin->lock_version])
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->active);
        $this->assertTrue($admin->fresh()->has_global_access);
    }

    public function test_global_access_can_only_be_granted_by_authorized_global_admin(): void
    {
        $manager = $this->employee('showroom_manager', [$this->showroom]);
        $target = $this->employee('operator', [$this->showroom]);

        $this->actingAs($manager)
            ->patch(route('users.global-access', $target), ['active' => true, 'lock_version' => $target->lock_version])
            ->assertForbidden();
        $this->assertFalse($target->fresh()->has_global_access);

        $admin = $this->administrator();
        $this->actingAs($admin)
            ->patch(route('users.global-access', $target), ['active' => true, 'lock_version' => $target->lock_version])
            ->assertSessionHasNoErrors();

        $this->assertTrue($target->fresh()->has_global_access);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.global_access_granted', 'subject_id' => $target->id]);
    }

    public function test_manager_cannot_edit_employee_with_stronger_role(): void
    {
        $manager = $this->employee('showroom_manager', [$this->showroom]);
        $director = $this->employee('director', [$this->showroom]);

        $this->actingAs($manager)
            ->put(route('users.update', $director), [...$this->updatePayload($director), 'name' => 'Взлом'])
            ->assertForbidden();

        $this->assertNotSame('Взлом', $director->fresh()->name);
    }

    public function test_manager_cannot_reach_role_management(): void
    {
        $manager = $this->employee('showroom_manager', [$this->showroom]);
        $operator = $this->role('operator');

        $this->actingAs($manager)->get(route('roles.index'))->assertForbidden();
        $this->actingAs($manager)
            ->put(route('roles.update', $operator), [
                'label' => 'Оператор',
                'permissions' => PermissionCatalog::all(),
                'lock_version' => $operator->lock_version,
            ])
            ->assertForbidden();

        $this->assertSame(['workspace.view'], $operator->fresh()->permissions->pluck('name')->all());
    }

    public function test_role_editor_cannot_grant_permissions_they_do_not_hold(): void
    {
        $editorRole = Role::query()->create(['name' => 'role_editor', 'label' => 'Редактор ролей', 'guard_name' => 'web']);
        $editorRole->syncPermissions([PermissionCatalog::WORKSPACE_VIEW, PermissionCatalog::ROLES_MANAGE]);
        $editor = $this->employee('role_editor', [], ['has_global_access' => true]);
        $operator = $this->role('operator');

        $this->actingAs($editor)
            ->put(route('roles.update', $operator), [
                'label' => 'Оператор',
                'permissions' => [PermissionCatalog::WORKSPACE_VIEW, PermissionCatalog::USERS_MANAGE],
                'lock_version' => $operator->lock_version,
            ])
            ->assertSessionHasErrors('permissions');

        $this->actingAs($editor)
            ->post(route('roles.store'), [
                'name' => 'sneaky',
                'label' => 'Хитрая',
                'permissions' => [PermissionCatalog::ACCESS_GRANT_GLOBAL],
            ])
            ->assertSessionHasErrors('permissions');

        $this->actingAs($editor)
            ->put(route('roles.update', $editorRole), [
                'label' => 'Редактор ролей',
                'permissions' => PermissionCatalog::all(),
                'lock_version' => $editorRole->lock_version,
            ])
            ->assertSessionHasErrors('permissions');

        $this->assertDatabaseMissing('roles', ['name' => 'sneaky']);
        $this->assertSame(['workspace.view'], $operator->fresh()->permissions->pluck('name')->all());
    }

    public function test_unknown_permissions_are_rejected(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('roles.store'), ['name' => 'fake', 'label' => 'Фейк', 'permissions' => ['leads.delete']])
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('roles', ['name' => 'fake']);
    }

    public function test_admin_manages_roles_and_changes_are_audited(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('roles.store'), [
                'name' => 'senior_operator',
                'label' => 'Старший оператор',
                'permissions' => [PermissionCatalog::WORKSPACE_VIEW, PermissionCatalog::USERS_VIEW],
            ])
            ->assertSessionHasNoErrors();

        $role = $this->role('senior_operator');
        $this->assertEqualsCanonicalizing(['workspace.view', 'users.view'], $role->permissions->pluck('name')->all());

        $this->actingAs($admin)
            ->put(route('roles.update', $role), [
                'label' => 'Старший оператор КЦ',
                'permissions' => [PermissionCatalog::WORKSPACE_VIEW],
                'lock_version' => $role->lock_version,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Старший оператор КЦ', $role->fresh()->label);

        $this->actingAs($admin)->delete(route('roles.destroy', $role))->assertRedirect(route('roles.index'));
        $this->assertDatabaseMissing('roles', ['name' => 'senior_operator']);

        foreach (['role.created', 'role.updated', 'role.deleted'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event' => $event]);
        }
    }

    public function test_system_administrator_role_is_protected(): void
    {
        $admin = $this->administrator();
        $role = $this->role('administrator');

        $this->actingAs($admin)->delete(route('roles.destroy', $role))->assertForbidden();

        $this->actingAs($admin)
            ->put(route('roles.update', $role), [
                'label' => 'Администратор',
                'permissions' => [PermissionCatalog::WORKSPACE_VIEW],
                'lock_version' => $role->lock_version,
            ])
            ->assertSessionHasErrors('permissions');

        $this->assertCount(count(PermissionCatalog::all()), $role->fresh()->permissions);
    }
}
