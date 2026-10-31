<?php

namespace Tests\Feature\Auth;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Registration is invite-only once the app has its first member (see
     * FirstMemberRegistrationTest for a fresh install).
     */
    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create();
    }

    public function test_registration_screen_is_not_found_without_an_invitation()
    {
        $this->get(route('register'))->assertNotFound();
    }

    public function test_registration_screen_is_not_found_with_an_expired_invitation()
    {
        $invitation = TeamInvitation::factory()->create(['expires_at' => now()->subDay()]);

        $this->get(route('register', ['invitation' => $invitation->code]))->assertNotFound();
    }

    public function test_registration_screen_is_not_found_with_an_unknown_invitation()
    {
        $this->get(route('register', ['invitation' => 'unknown']))->assertNotFound();
    }

    public function test_registration_screen_includes_team_invitation_context()
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['name' => 'Laravel Team']);
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

        $invitation = TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'email' => 'invited@example.com',
            'invited_by' => $owner->id,
        ]);

        $response = $this->get(route('register', ['invitation' => $invitation->code]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('auth/register')
            ->where('teamInvitation.code', $invitation->code)
            ->where('teamInvitation.teamName', 'Laravel Team'),
        );
    }

    public function test_users_without_a_pending_invitation_cannot_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_users_with_an_expired_invitation_cannot_register()
    {
        TeamInvitation::factory()->create(['email' => 'test@example.com', 'expires_at' => now()->subDay()]);

        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_invited_users_can_register()
    {
        TeamInvitation::factory()->create(['email' => 'test@example.com']);

        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();

        $user = User::where('email', 'test@example.com')->first();
        $response->assertRedirect(route('dashboard'));
    }
}
