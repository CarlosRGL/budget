<?php

namespace Tests\Feature\Accounts;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_budget_account_starts_with_its_balance_in_ready_to_assign()
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this->actingAs($user)->post(route('accounts.store', ['current_team' => $team]), [
            'name' => 'Crédit Agricole',
            'type' => 'checking',
            'on_budget' => true,
            'balance' => 149_675,
            'starting_date' => '2026-10-30',
        ]);

        $account = Account::sole();
        $response->assertRedirect(route('accounts.show', ['current_team' => $team, 'account' => $account]));

        $this->assertSame($team->id, $account->team_id);
        $this->assertSame('Crédit Agricole', $account->name);
        $this->assertSame(AccountType::Checking, $account->type);
        $this->assertTrue($account->on_budget);
        $this->assertSame('2026-10-30', $account->starting_date->toDateString());

        $transaction = Transaction::sole();
        $this->assertSame($account->id, $transaction->account_id);
        $this->assertSame(149_675, $transaction->amount);
        $this->assertSame('2026-10-30', $transaction->date->toDateString());
        $this->assertTrue($transaction->is_starting_balance);
        $this->assertTrue($transaction->inflow_to_rta);
        $this->assertTrue($transaction->cleared);
        $this->assertNotNull($transaction->approved_at);
        $this->assertSame($user->id, $transaction->approved_by);
        $this->assertSame($user->id, $transaction->created_by);
        $this->assertSame('Starting balance', $transaction->payee?->name);
    }

    public function test_a_loan_is_off_budget_and_does_not_feed_ready_to_assign()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('accounts.store', ['current_team' => $user->currentTeam]), [
            'name' => 'Crédit Maison',
            'type' => 'loan',
            'on_budget' => false,
            'balance' => -187_480,
            'starting_date' => '2026-10-30',
        ])->assertSessionHasNoErrors();

        $account = Account::sole();
        $this->assertFalse($account->on_budget);

        $transaction = Transaction::sole();
        $this->assertSame(-187_480, $transaction->amount);
        $this->assertTrue($transaction->is_starting_balance);
        $this->assertFalse($transaction->inflow_to_rta);
    }

    public function test_the_starting_balance_payee_is_shared_by_the_household_accounts()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (['Livret A', 'Livret A Camille'] as $name) {
            $this->post(route('accounts.store', ['current_team' => $user->currentTeam]), [
                'name' => $name,
                'type' => 'savings',
                'on_budget' => true,
                'balance' => 133_000,
                'starting_date' => '2026-10-30',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(1, Payee::where('name', 'Starting balance')->count());
        $this->assertSame([1, 2], Account::orderBy('sort')->pluck('sort')->all());
    }

    public function test_the_account_is_validated()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('accounts.store', ['current_team' => $user->currentTeam]), [
            'name' => '',
            'type' => 'piggy-bank',
            'on_budget' => true,
            'balance' => '12,50',
            'starting_date' => 'hier',
        ])->assertSessionHasErrors(['name', 'type', 'balance', 'starting_date']);

        $this->assertSame(0, Account::count());
    }
}
