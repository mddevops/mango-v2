<?php

namespace Tests\Feature\Organization;

use App\Models\Department;
use App\Models\Showroom;
use App\Models\User;
use App\Services\AdministratorGuard;
use App\Support\RoleCatalog;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\TestCase;

/**
 * The create buttons on /showrooms and /departments come from server-side policy
 * flags (`can.create`, `can.createShared`), which need both the permission and
 * global access. An account that holds the administrator role but lacks global
 * access is not a full administrator and must not see them.
 */
class CreationAccessTest extends TestCase
{
    use BuildsOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
    }

    /**
     * The state found in the working database: the role was attached outside the
     * application, so has_global_access stayed false.
     */
    private function roleOnlyAdministrator(): User
    {
        $user = User::factory()->create(['email' => 'role.only@example.com', 'has_global_access' => false]);
        $user->assignRole(RoleCatalog::ADMINISTRATOR);

        return $user->fresh();
    }

    /**
     * @return array<string, string>
     */
    private function showroomPayload(): array
    {
        return ['name' => 'Москва 1', 'address' => '', 'phone' => '', 'timezone' => 'Europe/Moscow'];
    }

    public function test_administrator_role_without_global_access_gets_no_create_actions(): void
    {
        $user = $this->roleOnlyAdministrator();

        $this->assertSame(0, app(AdministratorGuard::class)->count());

        $this->actingAs($user)->get(route('showrooms.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.create', false)
                ->where('auth.globalAccess', false)
                ->etc());

        $this->actingAs($user)->get(route('departments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.createShared', false)
                ->where('showrooms', [])
                ->etc());

        $this->actingAs($user)->post(route('showrooms.store'), $this->showroomPayload())->assertForbidden();
        $this->actingAs($user)->post(route('departments.store'), [
            'showroom_id' => null, 'name' => 'Продажи', 'kind' => 'other',
        ])->assertForbidden();

        $this->assertSame(0, Showroom::query()->count());
    }

    public function test_promote_admin_repairs_role_only_account_and_unlocks_creation(): void
    {
        $user = $this->roleOnlyAdministrator();
        $bystander = User::factory()->create(['has_global_access' => false]);

        $this->artisan('app:promote-admin', ['email' => $user->email, '--force' => true])->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->has_global_access);
        $this->assertSame(1, app(AdministratorGuard::class)->count());
        $this->assertFalse($bystander->fresh()->has_global_access);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'user.global_access_granted')->where('subject_id', $user->id)->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'user.roles_changed')->count(), 'The role was already assigned.');

        $this->actingAs($user)->get(route('showrooms.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.create', true)->where('auth.globalAccess', true)->etc());

        $this->actingAs($user)->post(route('showrooms.store'), $this->showroomPayload())->assertSessionHasNoErrors();
        $showroom = Showroom::query()->where('code', 'moskva-1')->firstOrFail();

        $this->actingAs($user)->get(route('departments.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.create', true)->where('can.createShared', true)->etc());

        $this->actingAs($user)->post(route('departments.store'), [
            'showroom_id' => $showroom->id, 'name' => 'Продажи', 'kind' => 'showroom',
        ])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('departments.store'), [
            'showroom_id' => null, 'name' => 'Кредитный отдел', 'kind' => 'credit',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Department::query()->where('showroom_id', $showroom->id)->where('code', 'prodazhi')->exists());
        $this->assertTrue(Department::query()->whereNull('showroom_id')->where('code', 'kreditnyy-otdel')->exists());
    }

    public function test_employee_without_manage_permission_sees_no_create_and_is_forbidden(): void
    {
        $showroom = Showroom::query()->create(['code' => 'spb', 'name' => 'Санкт-Петербург', 'timezone' => 'Europe/Moscow']);
        $manager = $this->employee('showroom_manager', [$showroom]);

        $this->actingAs($manager)->get(route('showrooms.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.create', false)->etc());
        $this->actingAs($manager)->get(route('departments.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.create', false)->where('can.createShared', false)->etc());

        $this->actingAs($manager)->post(route('showrooms.store'), $this->showroomPayload())->assertForbidden();
        $this->actingAs($manager)->post(route('departments.store'), [
            'showroom_id' => $showroom->id, 'name' => 'Продажи', 'kind' => 'showroom',
        ])->assertForbidden();

        $this->assertFalse($manager->fresh()->has_global_access);
        $this->assertSame(0, Department::query()->where('showroom_id', $showroom->id)->count());
    }
}
