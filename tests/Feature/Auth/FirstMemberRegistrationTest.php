<?php

namespace Tests\Feature\Auth;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * On a fresh install (no member at all), the first person registers without an
 * invitation and opens the household.
 */
class FirstMemberRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_install_sends_guests_to_the_registration(): void
    {
        $this->get(route('home'))->assertRedirect(route('register'));
        $this->get(route('login'))->assertRedirect(route('register'));
    }

    public function test_the_registration_screen_asks_for_the_household_name(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/register')
                ->where('firstMember.household', 'My household')
                ->missing('teamInvitation'));
    }

    public function test_the_first_member_opens_the_household_without_an_invitation(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Alex',
            'email' => 'alex@example.com',
            'household' => 'Familia',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();

        $user = User::sole();
        $team = $user->currentTeam;

        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('Familia', $team->name);
        $this->assertFalse($team->is_personal);
        $this->assertNull($team->onboarded_at);
        $this->assertSame(TeamRole::Owner, $user->teamRole($team));
        $response->assertRedirect(route('dashboard', ['current_team' => $team->slug]));
    }

    public function test_the_household_name_defaults_in_the_members_language(): void
    {
        $this->withHeader('Accept-Language', 'fr')->post(route('register.store'), [
            'name' => 'Alex',
            'email' => 'alex@example.com',
            'household' => '',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertSame('Mon foyer', User::sole()->currentTeam->name);
    }

    public function test_registration_is_invite_only_again_once_a_member_exists(): void
    {
        User::factory()->create();

        $this->get(route('register'))->assertNotFound();
        $this->get(route('login'))->assertOk();
        $this->get(route('home'))->assertRedirect(route('login'));

        $this->post(route('register.store'), [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'household' => 'Mine',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(1, User::count());
    }
}
