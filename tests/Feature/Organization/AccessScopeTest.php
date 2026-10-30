<?php

namespace Tests\Feature\Organization;

use App\Models\Department;
use App\Models\Showroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\TestCase;

class AccessScopeTest extends TestCase
{
    use BuildsOrganization;
    use RefreshDatabase;

    private Showroom $own;

    private Showroom $foreign;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->own = Showroom::factory()->create(['name' => 'Свой салон']);
        $this->foreign = Showroom::factory()->create(['name' => 'Чужой салон']);
        $this->manager = $this->employee('showroom_manager', [$this->own]);
    }

    public function test_guest_is_redirected_from_every_organization_page(): void
    {
        foreach (['showrooms.index', 'departments.index', 'users.index', 'roles.index', 'audit.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_operator_has_no_access_to_management_pages(): void
    {
        $operator = $this->employee('operator', [$this->own]);

        foreach (['showrooms.index', 'departments.index', 'users.index', 'users.create', 'roles.index', 'audit.index'] as $route) {
            $this->actingAs($operator)->get(route($route))->assertForbidden();
        }

        $this->actingAs($operator)
            ->put(route('showrooms.update', $this->own), ['name' => 'X', 'timezone' => 'Europe/Moscow', 'lock_version' => 0])
            ->assertForbidden();
    }

    public function test_showroom_list_contains_only_accessible_showrooms_even_with_search(): void
    {
        $this->actingAs($this->manager)
            ->get(route('showrooms.index', ['search' => 'салон']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('showrooms/index')
                ->has('showrooms.data', 1)
                ->where('showrooms.data.0.id', $this->own->id)
                ->where('showrooms.meta.total', 1));
    }

    public function test_foreign_showroom_cannot_be_changed_by_direct_url(): void
    {
        $this->actingAs($this->manager)
            ->put(route('showrooms.update', $this->foreign), [
                'name' => 'Взлом',
                'timezone' => 'Europe/Moscow',
                'lock_version' => $this->foreign->lock_version,
            ])
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->patch(route('showrooms.status', $this->foreign), ['active' => false, 'lock_version' => 0])
            ->assertForbidden();

        $this->assertSame('Чужой салон', $this->foreign->fresh()->name);
        $this->assertTrue($this->foreign->fresh()->active);
    }

    public function test_showroom_creation_requires_global_access(): void
    {
        $this->actingAs($this->manager)
            ->post(route('showrooms.store'), ['name' => 'Новый', 'timezone' => 'Europe/Moscow'])
            ->assertForbidden();

        $this->assertDatabaseMissing('showrooms', ['name' => 'Новый']);
    }

    public function test_foreign_departments_are_hidden_and_protected(): void
    {
        $ownDepartment = Department::factory()->for($this->own)->create();
        $foreignDepartment = Department::factory()->for($this->foreign)->create(['name' => 'Чужой отдел']);

        $this->actingAs($this->manager)
            ->get(route('departments.index', ['search' => 'отдел']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('departments.data', fn ($rows) => collect($rows)->pluck('id')->doesntContain($foreignDepartment->id))
                ->where('departments.data', fn ($rows) => collect($rows)->pluck('id')->contains($ownDepartment->id)));

        $this->actingAs($this->manager)
            ->put(route('departments.update', $foreignDepartment), ['name' => 'Взлом', 'lock_version' => 0])
            ->assertForbidden();

        $this->assertSame('Чужой отдел', $foreignDepartment->fresh()->name);
    }

    public function test_department_cannot_be_created_in_foreign_or_shared_scope_by_payload(): void
    {
        $manager = $this->employee(['showroom_manager'], [$this->own]);
        $manager->givePermissionTo('departments.manage');

        $this->actingAs($manager)
            ->post(route('departments.store'), [
                'showroom_id' => $this->foreign->id,
                'name' => 'Продажи',
                'kind' => 'showroom',
            ])
            ->assertForbidden();

        $this->actingAs($manager)
            ->post(route('departments.store'), [
                'showroom_id' => null,
                'name' => 'Общий',
                'kind' => 'other',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('departments', ['name' => 'Продажи']);
        $this->assertDatabaseMissing('departments', ['name' => 'Общий']);
    }

    public function test_user_list_is_scoped_before_search_and_or_conditions(): void
    {
        $colleague = $this->employee('operator', [$this->own], ['name' => 'Иван Свой', 'email' => 'ivan.own@example.com']);
        $stranger = $this->employee('operator', [$this->foreign], ['name' => 'Иван Чужой', 'email' => 'ivan.foreign@example.com']);

        $this->actingAs($this->manager)
            ->get(route('users.index', ['search' => 'ivan']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.meta.total', 1)
                ->where('users.data.0.id', $colleague->id));

        $this->actingAs($this->manager)
            ->get(route('users.index', ['showroom' => $this->foreign->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.filter', [])
                ->where('users.data', fn ($rows) => collect($rows)->pluck('id')->doesntContain($stranger->id)));
    }

    public function test_foreign_employee_cannot_be_opened_or_changed_by_direct_url(): void
    {
        $stranger = $this->employee('operator', [$this->foreign]);

        $this->actingAs($this->manager)->get(route('users.edit', $stranger))->assertForbidden();

        $this->actingAs($this->manager)
            ->put(route('users.update', $stranger), $this->updatePayload($stranger, ['name' => 'Взлом']))
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->patch(route('users.status', $stranger), ['active' => false, 'lock_version' => $stranger->lock_version])
            ->assertForbidden();

        $this->assertNotSame('Взлом', $stranger->fresh()->name);
        $this->assertTrue($stranger->fresh()->active);
    }

    public function test_modified_payload_cannot_assign_foreign_showroom_department_or_role(): void
    {
        $foreignDepartment = Department::factory()->for($this->foreign)->create();
        $administrator = $this->role('administrator');

        $this->actingAs($this->manager)
            ->post(route('users.store'), $this->userPayload([
                'email' => 'payload1@example.com',
                'showroom_ids' => [$this->own->id, $this->foreign->id],
            ]))
            ->assertSessionHasErrors('showroom_ids');

        $this->actingAs($this->manager)
            ->post(route('users.store'), $this->userPayload([
                'email' => 'payload2@example.com',
                'showroom_ids' => [$this->own->id],
                'department_ids' => [$foreignDepartment->id],
            ]))
            ->assertSessionHasErrors('department_ids');

        $this->actingAs($this->manager)
            ->post(route('users.store'), $this->userPayload([
                'email' => 'payload3@example.com',
                'showroom_ids' => [$this->own->id],
                'role_ids' => [$administrator->id],
            ]))
            ->assertSessionHasErrors('role_ids');

        $this->actingAs($this->manager)
            ->post(route('users.store'), $this->userPayload([
                'email' => 'payload4@example.com',
                'showroom_ids' => [$this->own->id],
                'has_global_access' => true,
                'role_ids' => [999999],
            ]))
            ->assertSessionHasErrors('role_ids');

        $this->assertDatabaseMissing('users', ['email' => 'payload1@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'payload2@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'payload3@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'payload4@example.com']);
    }

    public function test_manager_creates_employee_in_own_showroom_and_extra_fields_are_ignored(): void
    {
        $department = Department::factory()->for($this->own)->create();
        $operator = $this->role('operator');

        $this->actingAs($this->manager)
            ->post(route('users.store'), $this->userPayload([
                'email' => 'created@example.com',
                'showroom_ids' => [$this->own->id],
                'department_ids' => [$department->id],
                'role_ids' => [$operator->id],
                'has_global_access' => true,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $created = User::query()->where('email', 'created@example.com')->firstOrFail();
        $this->assertFalse($created->has_global_access);
        $this->assertTrue($created->hasRole('operator'));
        $this->assertSame([$this->own->id], $created->showrooms()->pluck('showrooms.id')->all());
        $this->assertSame([$department->id], $created->departments()->pluck('departments.id')->all());
    }

    public function test_manager_must_give_new_employee_one_of_own_showrooms(): void
    {
        $this->actingAs($this->manager)
            ->post(route('users.store'), $this->userPayload(['email' => 'noshowroom@example.com']))
            ->assertSessionHasErrors('showroom_ids');
    }

    public function test_out_of_scope_assignments_are_preserved_on_update(): void
    {
        $shared = $this->employee('operator', [$this->own, $this->foreign]);

        $this->actingAs($this->manager)
            ->get(route('users.edit', $shared))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('user.showroom_ids', [$this->own->id])
                ->where('user.hidden_assignments', 1));

        $this->actingAs($this->manager)
            ->put(route('users.update', $shared), [
                ...$this->updatePayload($shared),
                'showroom_ids' => [$this->own->id],
                'name' => 'Обновлён',
            ])
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [$this->own->id, $this->foreign->id],
            $shared->fresh()->showrooms()->pluck('showrooms.id')->all(),
        );
    }
}
