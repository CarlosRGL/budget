<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\CreateAccount;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SidebarAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_lists_accounts_by_section_with_totals()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $open = fn (string $name, AccountType $type, int $balance) => app(CreateAccount::class)
            ->handle($user, $name, $type, $type->isOnBudgetByDefault(), $balance, Carbon::parse('2026-10-01'));

        $checking = $open('Crédit Agricole', AccountType::Checking, 149_675);
        $open('Livret A', AccountType::Savings, 133_000);
        $open('Crédit Maison', AccountType::Loan, -187_480);
        $open('PEA', AccountType::Investment, 0);
        $open('Crypto', AccountType::Investment, 25_000);
        $open('Ancien compte', AccountType::Checking, 1_000)->update(['closed_at' => now()]);

        Transaction::factory()->for($user->currentTeam)->for($checking)->create(['amount' => -2_156]);

        // Another household's accounts never show up.
        Account::factory()->create(['name' => 'Autre banque']);

        $this->get(route('budget.show', ['current_team' => $user->currentTeam]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sidebarAccounts.sections', 3)
                ->where('sidebarAccounts.sections.0.key', 'budget')
                ->where('sidebarAccounts.sections.0.total', 149_675 - 2_156 + 133_000)
                ->where('sidebarAccounts.sections.0.accounts.0.name', 'Crédit Agricole')
                ->where('sidebarAccounts.sections.0.accounts.0.balance', 149_675 - 2_156)
                ->where('sidebarAccounts.sections.0.accounts.1.name', 'Livret A')
                ->where('sidebarAccounts.sections.1.key', 'loans')
                ->where('sidebarAccounts.sections.1.total', -187_480)
                ->has('sidebarAccounts.sections.1.accounts', 1)
                ->where('sidebarAccounts.sections.2.key', 'tracking')
                ->where('sidebarAccounts.sections.2.total', 25_000)
                ->has('sidebarAccounts.sections.2.accounts', 2)
                ->has('sidebarAccounts.closed', 1)
                ->where('sidebarAccounts.closed.0.name', 'Ancien compte')
                ->where('sidebarAccounts.closed.0.balance', 1_000)
                ->has('sidebarAccounts.types', 5));
    }

    public function test_empty_sections_are_left_out()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('budget.show', ['current_team' => $user->currentTeam]))
            ->assertInertia(fn ($page) => $page
                ->has('sidebarAccounts.sections', 0)
                ->has('sidebarAccounts.closed', 0));
    }
}
