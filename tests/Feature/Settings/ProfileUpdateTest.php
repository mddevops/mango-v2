<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_employee_cannot_delete_own_account_through_profile()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertStatus(405);

        $this->assertNotNull($user->fresh());
    }

    public function test_profile_update_ignores_roles_and_access_fields()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
                'active' => false,
                'has_global_access' => true,
                'role_ids' => [1],
                'showroom_ids' => [1],
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertTrue($user->active);
        $this->assertFalse($user->has_global_access);
        $this->assertCount(0, $user->roles);
        $this->assertCount(0, $user->showrooms);
    }
}
