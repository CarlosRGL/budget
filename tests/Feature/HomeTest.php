<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page()
    {
        User::factory()->create();

        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_members_are_sent_to_their_dashboard()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertRedirect(route('dashboard', ['current_team' => $user->currentTeam->slug]));
    }
}
