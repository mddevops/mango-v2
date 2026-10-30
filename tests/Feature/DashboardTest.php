<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Showroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use BuildsOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_employee_sees_own_roles_showrooms_and_departments()
    {
        $showroom = Showroom::factory()->create(['name' => 'Север']);
        Showroom::factory()->create(['name' => 'Юг']);
        $department = Department::factory()->for($showroom)->create(['name' => 'Продажи']);
        $user = $this->employee('operator', [$showroom]);
        $user->departments()->attach($department);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('employee.name', $user->name)
                ->where('employee.globalAccess', false)
                ->where('employee.roles.0.label', 'Оператор')
                ->has('employee.showrooms', 1)
                ->where('employee.showrooms.0.name', 'Север')
                ->where('employee.departments.0.name', 'Продажи')
                ->where('auth.permissions', ['workspace.view']));
    }

    public function test_user_without_roles_has_no_access_to_workspace()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    }
}
