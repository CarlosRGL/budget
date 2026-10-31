<?php

namespace Tests\Feature\Console;

use App\Budget\BudgetCalculator;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fills_a_household_with_a_consistent_demo_budget()
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00'));
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $this->artisan('budget:demo', ['household' => $team->slug])->assertSuccessful();

        $this->assertSame(5, Account::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertSame(22, Category::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertSame(3, Transaction::withoutGlobalScopes()->where('team_id', $team->id)->whereNull('approved_at')->count());

        $budget = app(BudgetCalculator::class)->forMonth($team, CarbonImmutable::parse('2026-10-01'));
        $this->assertGreaterThan(0, $budget->readyToAssign);
        $this->assertSame(1, $budget->overspentCount());
        $this->assertSame(-49_99, $budget->uncategorizedActivity);

        // A second run refuses to mix demo data with existing data.
        $this->artisan('budget:demo', ['household' => $team->slug])->assertFailed();
    }

    public function test_remove_deletes_every_budget_data_of_the_household_only()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->artisan('budget:demo', ['household' => $user->currentTeam->slug])->assertSuccessful();
        $this->artisan('budget:demo', ['household' => $other->currentTeam->slug])->assertSuccessful();

        $this->artisan('budget:demo', ['household' => $user->currentTeam->slug, '--remove' => true])
            ->expectsConfirmation("Delete every account, category and transaction of “{$user->currentTeam->name}”?", 'yes')
            ->assertSuccessful();

        $this->assertSame(0, Account::withoutGlobalScopes()->where('team_id', $user->currentTeam->id)->count());
        $this->assertSame(0, Transaction::withoutGlobalScopes()->where('team_id', $user->currentTeam->id)->count());
        $this->assertSame(5, Account::withoutGlobalScopes()->where('team_id', $other->currentTeam->id)->count());
    }
}
