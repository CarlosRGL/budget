<?php

namespace Tests\Feature\Banking;

use App\Models\BankConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class BankSyncSetupTest extends TestCase
{
    use FakesEnableBanking, RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_the_guide_shows_what_is_missing_when_nothing_is_configured(): void
    {
        config([
            'services.enablebanking.app_id' => null,
            'services.enablebanking.private_key' => null,
            'services.enablebanking.private_key_path' => '/nonexistent/enablebanking.pem',
        ]);

        $this->actingAs($this->user)
            ->get(route('bank-connections.setup', ['current_team' => $this->team]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('bank-connections/setup')
                ->where('status', ['appId' => false, 'keyFound' => false, 'keyValid' => false, 'connected' => false])
                ->where('callbackUrl', route('bank.callback')));
    }

    public function test_the_guide_shows_a_complete_setup(): void
    {
        $this->configureEnableBanking();
        BankConnection::factory()->for($this->team)->for($this->user)->create();

        $this->actingAs($this->user)
            ->get(route('bank-connections.setup', ['current_team' => $this->team]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('status', ['appId' => true, 'keyFound' => true, 'keyValid' => true, 'connected' => true]));
    }

    public function test_a_key_given_in_the_environment_is_checked_too(): void
    {
        $this->configureEnableBanking();
        config(['services.enablebanking.private_key' => base64_encode($this->privateKeyPem)]);

        $this->actingAs($this->user)
            ->get(route('bank-connections.setup', ['current_team' => $this->team]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('status.keyFound', true)
                ->where('status.keyValid', true));
    }

    public function test_a_file_that_is_not_a_private_key_is_reported_as_invalid(): void
    {
        $this->configureEnableBanking();
        config(['services.enablebanking.private_key' => base64_encode($this->publicKey)]);

        $this->actingAs($this->user)
            ->get(route('bank-connections.setup', ['current_team' => $this->team]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('status.keyFound', true)
                ->where('status.keyValid', false));
    }

    public function test_a_viewer_is_sent_back_to_the_simple_view(): void
    {
        $viewer = User::factory()->create();
        $this->team->members()->attach($viewer->id, ['role' => 'viewer']);
        $viewer->forceFill(['current_team_id' => $this->team->id])->save();

        $this->actingAs($viewer)
            ->get(route('bank-connections.setup', ['current_team' => $this->team]))
            ->assertRedirect(route('simple.overview', ['current_team' => $this->team]));
    }
}
