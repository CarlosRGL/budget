<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard is now the budget screen: see tests/Feature/BudgetScreen/BudgetPageTest.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $user = User::factory()->create();

        $this->get(route('dashboard', ['current_team' => $user->currentTeam]))
            ->assertRedirect(route('login'));
    }

    public function test_members_are_sent_to_the_budget()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $user->currentTeam]))
            ->assertRedirect(route('budget.show', ['current_team' => $user->currentTeam]));
    }
}
