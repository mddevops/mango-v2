<?php

namespace Tests\Feature\Organization;

use App\Models\Showroom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\TestCase;

class UserStatusTest extends TestCase
{
    use BuildsOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_disabled_user_with_existing_session_is_logged_out_on_next_request(): void
    {
        $user = $this->employee('operator');

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['active' => false])->save();

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertGuest();
    }

    public function test_disabled_user_cannot_log_in(): void
    {
        $user = $this->employee('operator', [], ['active' => false]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivation_terminates_database_sessions_and_rotates_remember_token(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->administrator();
        $user = $this->employee('operator');
        $token = $user->remember_token;

        DB::table('sessions')->insert([
            'id' => 'session-of-user',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin)
            ->patch(route('users.status', $user), ['active' => false, 'lock_version' => $user->lock_version])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertFalse($user->active);
        $this->assertNotSame($token, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'session-of-user']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.deactivated', 'subject_id' => $user->id]);
    }

    public function test_stale_lock_version_is_rejected(): void
    {
        $admin = $this->administrator();
        $user = $this->employee('operator');
        $stale = $user->lock_version;

        $this->actingAs($admin)
            ->put(route('users.update', $user), $this->updatePayload($user, ['name' => 'Первое изменение']))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put(route('users.update', $user), [...$this->updatePayload($user), 'lock_version' => $stale, 'name' => 'Второе'])
            ->assertSessionHasErrors('lock_version');

        $this->assertSame('Первое изменение', $user->fresh()->name);
    }

    public function test_admin_sets_new_password_without_exposing_it(): void
    {
        $admin = $this->administrator();
        $user = $this->employee('operator');

        $this->actingAs($admin)
            ->put(route('users.password', $user), [
                'password' => 'Another-Secret-456',
                'password_confirmation' => 'Another-Secret-456',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Another-Secret-456', $user->fresh()->password));

        $log = DB::table('audit_logs')->where('event', 'user.password_set')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('Another-Secret-456', json_encode($log));
        $this->assertStringNotContainsString($user->fresh()->password, json_encode($log));
    }

    public function test_password_action_requires_its_own_permission(): void
    {
        $showroom = Showroom::factory()->create();
        $manager = $this->employee('showroom_manager', [$showroom]);
        $user = $this->employee('operator', [$showroom]);

        $this->actingAs($manager)
            ->put(route('users.password', $user), [
                'password' => 'Another-Secret-456',
                'password_confirmation' => 'Another-Secret-456',
            ])
            ->assertForbidden();
    }

    public function test_responses_never_contain_password_hashes_or_tokens(): void
    {
        $admin = $this->administrator();
        $user = $this->employee('operator');
        $user->forceFill(['two_factor_secret' => encrypt('secret-value')])->save();

        foreach ([route('users.index'), route('users.edit', $user), route('dashboard')] as $url) {
            $content = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString($user->password, $content);
            $this->assertStringNotContainsString($admin->password, $content);
            $this->assertStringNotContainsString((string) $user->remember_token, $content);
            $this->assertStringNotContainsString('two_factor_secret', $content);
            $this->assertStringNotContainsString('remember_token', $content);
        }

        $this->actingAs($admin)
            ->get(route('users.edit', $user))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('user.password')
                ->missing('auth.user.password')
                ->missing('auth.user.remember_token'));
    }
}
