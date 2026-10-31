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

class ManageAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_account_can_be_shown()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $this->openAccount($user, 'Crédit Agricole', AccountType::Checking, 149_675);

        $this->get(route('accounts.show', ['current_team' => $user->currentTeam, 'account' => $account]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('accounts/show')
                ->where('account.name', 'Crédit Agricole')
                ->where('account.balance', 149_675)
                ->where('account.canChangeBudgetStatus', true));
    }

    public function test_an_account_can_be_renamed_annotated_and_taken_off_budget()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $this->openAccount($user, 'Livret A', AccountType::Savings, 133_000);

        $this->patch(route('accounts.update', ['current_team' => $user->currentTeam, 'account' => $account]), [
            'name' => 'Livret A Alex',
            'note' => 'Plafond 22 950 €',
            'on_budget' => false,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $account->refresh();
        $this->assertSame('Livret A Alex', $account->name);
        $this->assertSame('Plafond 22 950 €', $account->note);
        $this->assertFalse($account->on_budget);
        $this->assertFalse(Transaction::sole()->inflow_to_rta);
    }

    public function test_the_budget_status_is_locked_once_the_account_has_transactions()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $this->openAccount($user, 'Boursorama', AccountType::Checking, 10_000);
        Transaction::factory()->for($user->currentTeam)->for($account)->create(['amount' => -2_156]);

        $this->patch(route('accounts.update', ['current_team' => $user->currentTeam, 'account' => $account]), [
            'name' => 'Boursorama',
            'on_budget' => false,
        ])->assertSessionHasErrors('on_budget');

        $this->assertTrue($account->refresh()->on_budget);

        $this->patch(route('accounts.update', ['current_team' => $user->currentTeam, 'account' => $account]), [
            'name' => 'Boursorama joint',
            'on_budget' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Boursorama joint', $account->refresh()->name);
    }

    public function test_an_account_can_be_closed_and_reopened()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $this->openAccount($user, 'Espèces', AccountType::Cash, 5_000);
        $parameters = ['current_team' => $user->currentTeam, 'account' => $account];

        $this->post(route('accounts.close', $parameters))->assertRedirect();
        $this->assertTrue($account->refresh()->isClosed());

        $this->post(route('accounts.reopen', $parameters))->assertRedirect();
        $this->assertFalse($account->refresh()->isClosed());
    }

    public function test_the_balance_of_an_off_budget_account_is_adjusted()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $pea = $this->openAccount($user, 'PEA', AccountType::Investment, 1_000_000);

        $this->post(route('accounts.balance', ['current_team' => $user->currentTeam, 'account' => $pea]), [
            'balance' => 1_052_550,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $adjustment = Transaction::where('is_starting_balance', false)->sole();
        $this->assertSame(52_550, $adjustment->amount);
        $this->assertSame('Balance adjustment', $adjustment->payee?->name);
        $this->assertSame(today()->toDateString(), $adjustment->date->toDateString());
        $this->assertTrue($adjustment->cleared);
        $this->assertNotNull($adjustment->approved_at);
        $this->assertSame($user->id, $adjustment->approved_by);
        $this->assertFalse($adjustment->inflow_to_rta);
        $this->assertSame(1_052_550, (int) $pea->transactions()->sum('amount'));
    }

    public function test_adjusting_to_the_same_balance_records_nothing()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $pea = $this->openAccount($user, 'PEA', AccountType::Investment, 1_000_000);

        $this->post(route('accounts.balance', ['current_team' => $user->currentTeam, 'account' => $pea]), [
            'balance' => 1_000_000,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::count());
    }

    public function test_reconciling_a_budget_account_goes_through_ready_to_assign()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $this->openAccount($user, 'Boursorama', AccountType::Checking, 9_237);

        $this->post(route('accounts.balance', ['current_team' => $user->currentTeam, 'account' => $account]), [
            'balance' => 3_333,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $adjustment = Transaction::where('is_starting_balance', false)->sole();
        $this->assertSame(-5_904, $adjustment->amount);
        $this->assertTrue($adjustment->inflow_to_rta);
        $this->assertNull($adjustment->category_id);
        $this->assertSame(3_333, (int) $account->transactions()->sum('amount'));
    }

    private function openAccount(User $user, string $name, AccountType $type, int $balance): Account
    {
        return app(CreateAccount::class)->handle($user, $name, $type, $type->isOnBudgetByDefault(), $balance, Carbon::parse('2026-10-01'));
    }
}
