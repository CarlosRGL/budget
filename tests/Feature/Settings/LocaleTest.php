<?php

namespace Tests\Feature\Settings;

use App\Models\Account;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_gets_the_interface_in_their_language()
    {
        $user = User::factory()->create(['locale' => 'fr']);
        $account = Account::factory()->for($user->currentTeam)->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn ($page) => $page->where('locale', 'fr'));

        $this->patch(route('accounts.update', ['current_team' => $user->currentTeam, 'account' => $account]), [
            'name' => 'Livret A',
            'on_budget' => $account->on_budget,
        ])->assertInertiaFlash('toast.message', 'Compte mis à jour.');
    }

    public function test_a_member_without_a_language_gets_the_default_one()
    {
        $user = User::factory()->create(['locale' => null]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn ($page) => $page->where('locale', 'en'));
    }

    public function test_a_guest_gets_the_language_of_their_browser()
    {
        User::factory()->create();

        $this->withHeader('Accept-Language', 'es-ES,es;q=0.9,en;q=0.5')
            ->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('locale', 'es'));

        $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')
            ->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('locale', 'en'));
    }

    public function test_the_language_is_saved_with_the_profile()
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'locale' => 'es'])
            ->assertSessionHasNoErrors();

        $this->assertSame('es', $user->refresh()->locale);
    }

    public function test_an_unsupported_language_is_rejected()
    {
        $user = User::factory()->create(['locale' => 'fr']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'locale' => 'de'])
            ->assertSessionHasErrors('locale');

        $this->assertSame('fr', $user->refresh()->locale);
    }

    public function test_emails_follow_the_language_of_the_member()
    {
        $this->assertSame('es', User::factory()->make(['locale' => 'es'])->preferredLocale());
        $this->assertNull(User::factory()->make(['locale' => 'de'])->preferredLocale());

        Notification::fake();
        $owner = User::factory()->create(['locale' => 'en']);
        $invitee = User::factory()->create(['locale' => 'fr']);

        $this->actingAs($owner)
            ->post(route('teams.invitations.store', $owner->currentTeam), ['email' => $invitee->email, 'role' => 'member']);

        Notification::assertSentOnDemand(
            TeamInvitationNotification::class,
            fn (TeamInvitationNotification $notification) => $notification->locale === 'fr',
        );
    }

    public function test_amounts_are_written_the_way_each_language_does()
    {
        $this->assertSame('€1,234.56', Money::format(123_456, 'en'));
        $this->assertSame("-1\u{202f}234,56\u{a0}€", Money::format(-123_456, 'fr'));
        $this->assertSame("1234,56\u{a0}€", Money::format(123_456, 'es'));
        $this->assertSame("12.345,67\u{a0}€", Money::format(1_234_567, 'es'));
    }
}
