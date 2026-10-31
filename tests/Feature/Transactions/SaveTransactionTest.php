<?php

namespace Tests\Feature\Transactions;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Payee;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaveTransactionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Account $checking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
        $this->checking = Account::factory()->for($this->team)->create(['name' => 'Boursorama']);
        $this->actingAs($this->user);
    }

    public function test_a_spending_with_a_new_payee_is_recorded_and_approved()
    {
        $courses = Category::factory()->for($this->team)->create(['name' => 'Courses']);

        $this->post($this->url('transactions.store'), [
            'account_id' => $this->checking->id,
            'date' => '2026-10-22',
            'payee_name' => 'Leclerc',
            'category_id' => $courses->id,
            'memo' => 'Plein du mois',
            'amount' => -18_09,
            'cleared' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $transaction = Transaction::sole();
        $payee = Payee::where('name', 'Leclerc')->sole();

        $this->assertSame($this->checking->id, $transaction->account_id);
        $this->assertSame('2026-10-22', $transaction->date->toDateString());
        $this->assertSame($payee->id, $transaction->payee_id);
        $this->assertSame($courses->id, $transaction->category_id);
        $this->assertSame('Plein du mois', $transaction->memo);
        $this->assertSame(-18_09, $transaction->amount);
        $this->assertTrue($transaction->cleared);
        $this->assertTrue($transaction->isApproved());
        $this->assertSame($this->user->id, $transaction->approved_by);
        $this->assertSame($this->user->id, $transaction->created_by);
        $this->assertSame($courses->id, $payee->lastCategory?->id);
    }

    public function test_an_existing_payee_is_reused()
    {
        $leclerc = Payee::factory()->for($this->team)->create(['name' => 'Leclerc']);

        $this->post($this->url('transactions.store'), $this->spending(['payee_id' => $leclerc->id]))->assertSessionHasNoErrors();

        $this->assertSame($leclerc->id, Transaction::sole()->payee_id);
        $this->assertSame(1, Payee::count());
    }

    public function test_income_can_go_to_ready_to_assign()
    {
        $this->post($this->url('transactions.store'), $this->spending([
            'payee_name' => 'Employeur', 'category_id' => null, 'inflow_to_rta' => true, 'amount' => 250_000,
        ]))->assertSessionHasNoErrors();

        $transaction = Transaction::sole();
        $this->assertTrue($transaction->inflow_to_rta);
        $this->assertNull($transaction->category_id);
    }

    public function test_a_split_transaction_stores_its_lines()
    {
        $courses = Category::factory()->for($this->team)->create();
        $couches = Category::factory()->for($this->team)->create();

        $this->post($this->url('transactions.store'), $this->spending([
            'category_id' => null,
            'amount' => -10_000,
            'splits' => [
                ['category_id' => $courses->id, 'amount' => -7_000, 'memo' => 'Repas'],
                ['category_id' => $couches->id, 'amount' => -3_000],
            ],
        ]))->assertSessionHasNoErrors();

        $transaction = Transaction::sole();
        $this->assertNull($transaction->category_id);
        $this->assertCount(2, $transaction->splits);
        $this->assertTrue($transaction->splitsAreBalanced());
        $this->assertSame('Repas', $transaction->splits->firstWhere('category_id', $courses->id)?->memo);
    }

    public function test_split_lines_must_add_up_to_the_amount()
    {
        $courses = Category::factory()->for($this->team)->create();
        $couches = Category::factory()->for($this->team)->create();

        $this->post($this->url('transactions.store'), $this->spending([
            'amount' => -10_000,
            'splits' => [
                ['category_id' => $courses->id, 'amount' => -7_000],
                ['category_id' => $couches->id, 'amount' => -2_000],
            ],
        ]))->assertSessionHasErrors('splits');

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_transfer_between_budget_accounts_creates_both_sides_without_category()
    {
        $savings = Account::factory()->for($this->team)->create(['name' => 'Livret A', 'type' => AccountType::Savings]);
        $courses = Category::factory()->for($this->team)->create();

        $this->post($this->url('transactions.store'), $this->spending([
            'payee_name' => null,
            'transfer_account_id' => $savings->id,
            'category_id' => $courses->id,
            'amount' => -50_000,
        ]))->assertSessionHasNoErrors();

        $outflow = Transaction::where('account_id', $this->checking->id)->sole();
        $inflow = Transaction::where('account_id', $savings->id)->sole();

        $this->assertSame(-50_000, $outflow->amount);
        $this->assertSame(50_000, $inflow->amount);
        $this->assertTrue($outflow->transferPair->is($inflow));
        $this->assertNull($outflow->category_id);
        $this->assertTrue($outflow->isApproved());
        $this->assertTrue($inflow->isApproved());
    }

    public function test_a_transfer_received_from_another_account_is_recorded_the_other_way()
    {
        $savings = Account::factory()->for($this->team)->create(['type' => AccountType::Savings]);

        $this->post($this->url('transactions.store'), $this->spending([
            'payee_name' => null, 'transfer_account_id' => $savings->id, 'amount' => 20_000,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(20_000, Transaction::where('account_id', $this->checking->id)->sole()->amount);
        $this->assertSame(-20_000, Transaction::where('account_id', $savings->id)->sole()->amount);
    }

    public function test_a_transfer_to_an_off_budget_account_keeps_its_category()
    {
        $loan = Account::factory()->for($this->team)->create(['type' => AccountType::Loan]);
        $credit = Category::factory()->for($this->team)->create(['name' => 'Crédit Maison']);

        $this->post($this->url('transactions.store'), $this->spending([
            'payee_name' => null, 'transfer_account_id' => $loan->id, 'category_id' => $credit->id, 'amount' => -93_740,
        ]))->assertSessionHasNoErrors();

        $this->assertSame($credit->id, Transaction::where('account_id', $this->checking->id)->sole()->category_id);
    }

    public function test_editing_a_transaction_approves_it()
    {
        $courses = Category::factory()->for($this->team)->create();
        $transaction = Transaction::factory()->for($this->team)->for($this->checking)->create(['amount' => -1_590, 'category_id' => null, 'approved_at' => null]);

        $this->patch($this->url('transactions.update', $transaction), $this->spending([
            'category_id' => $courses->id, 'amount' => -1_590,
        ]))->assertSessionHasNoErrors();

        $transaction->refresh();
        $this->assertSame($courses->id, $transaction->category_id);
        $this->assertTrue($transaction->isApproved());
        $this->assertSame($this->user->id, $transaction->approved_by);
    }

    public function test_editing_a_transfer_updates_both_sides()
    {
        $savings = Account::factory()->for($this->team)->create(['type' => AccountType::Savings]);
        [$outflow, $inflow] = Transaction::createTransfer($this->checking, $savings, 50_000, now());

        $this->patch($this->url('transactions.update', $outflow), $this->spending([
            'payee_name' => null, 'transfer_account_id' => $savings->id, 'amount' => -60_000, 'date' => '2026-10-25',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(-60_000, $outflow->fresh()->amount);
        $this->assertSame(60_000, $inflow->fresh()->amount);
        $this->assertSame('2026-10-25', $inflow->fresh()->date->toDateString());
    }

    public function test_changing_the_other_account_of_a_transfer_moves_the_other_side()
    {
        $savings = Account::factory()->for($this->team)->create(['type' => AccountType::Savings]);
        $other = Account::factory()->for($this->team)->create(['type' => AccountType::Savings]);
        [$outflow] = Transaction::createTransfer($this->checking, $savings, 50_000, now());

        $this->patch($this->url('transactions.update', $outflow), $this->spending([
            'payee_name' => null, 'transfer_account_id' => $other->id, 'amount' => -50_000,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, Transaction::where('account_id', $savings->id)->count());
        $this->assertSame(50_000, Transaction::where('account_id', $other->id)->sole()->amount);
        $this->assertSame(2, Transaction::count());
    }

    public function test_turning_a_transfer_into_a_spending_removes_the_other_side()
    {
        $savings = Account::factory()->for($this->team)->create(['type' => AccountType::Savings]);
        $courses = Category::factory()->for($this->team)->create();
        [$outflow] = Transaction::createTransfer($this->checking, $savings, 50_000, now());

        $this->patch($this->url('transactions.update', $outflow), $this->spending([
            'payee_name' => 'Leclerc', 'category_id' => $courses->id, 'amount' => -50_000,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::count());
        $this->assertNull($outflow->fresh()->transfer_pair_id);
        $this->assertSame($courses->id, $outflow->fresh()->category_id);
    }

    public function test_editing_a_split_replaces_its_lines()
    {
        $courses = Category::factory()->for($this->team)->create();
        $couches = Category::factory()->for($this->team)->create();
        $transaction = Transaction::factory()->for($this->team)->for($this->checking)->create(['amount' => -10_000, 'category_id' => null]);
        $transaction->splits()->create(['category_id' => $courses->id, 'amount' => -10_000]);

        $this->patch($this->url('transactions.update', $transaction), $this->spending([
            'category_id' => $couches->id, 'amount' => -10_000,
        ]))->assertSessionHasNoErrors();

        $this->assertCount(0, $transaction->fresh()->splits);
        $this->assertSame($couches->id, $transaction->fresh()->category_id);
    }

    public function test_deleting_a_transfer_deletes_both_sides()
    {
        $savings = Account::factory()->for($this->team)->create(['type' => AccountType::Savings]);
        [$outflow] = Transaction::createTransfer($this->checking, $savings, 50_000, now());

        $this->delete($this->url('transactions.destroy', $outflow))->assertRedirect();

        $this->assertSame(0, Transaction::count());
    }

    public function test_approving_several_transactions_at_once()
    {
        $first = Transaction::factory()->for($this->team)->for($this->checking)->create(['approved_at' => null]);
        $second = Transaction::factory()->for($this->team)->for($this->checking)->create(['approved_at' => null]);
        $stranger = User::factory()->create();
        $foreign = Transaction::factory()->for($stranger->currentTeam)->create(['approved_at' => null]);

        $this->post($this->url('transactions.approve'), ['ids' => [$first->id, $second->id, $foreign->id]])
            ->assertSessionHasErrors('ids.2');

        $this->post($this->url('transactions.approve'), ['ids' => [$first->id, $second->id]])->assertSessionHasNoErrors();

        $this->assertTrue($first->fresh()->isApproved());
        $this->assertSame($this->user->id, $second->fresh()->approved_by);
        $this->assertFalse(Transaction::withoutGlobalScopes()->find($foreign->id)->isApproved());
    }

    public function test_the_cleared_flag_can_be_toggled()
    {
        $transaction = Transaction::factory()->for($this->team)->for($this->checking)->create(['cleared' => false]);

        $this->patch($this->url('transactions.cleared', $transaction), ['cleared' => true])->assertSessionHasNoErrors();

        $this->assertTrue($transaction->fresh()->cleared);
    }

    public function test_another_households_records_cannot_be_used()
    {
        $stranger = User::factory()->create();
        $foreignAccount = Account::factory()->for($stranger->currentTeam)->create();
        $foreignCategory = Category::factory()->for($stranger->currentTeam)->create();
        $foreignTransaction = Transaction::factory()->for($stranger->currentTeam)->create();

        $this->post($this->url('transactions.store'), $this->spending(['account_id' => $foreignAccount->id]))->assertSessionHasErrors('account_id');
        $this->post($this->url('transactions.store'), $this->spending(['category_id' => $foreignCategory->id]))->assertSessionHasErrors('category_id');
        $this->post($this->url('transactions.store'), $this->spending(['transfer_account_id' => $foreignAccount->id]))->assertSessionHasErrors('transfer_account_id');
        $this->patch($this->url('transactions.update', $foreignTransaction), $this->spending())->assertNotFound();
        $this->delete($this->url('transactions.destroy', $foreignTransaction))->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function spending(array $overrides = []): array
    {
        return [
            'account_id' => $this->checking->id,
            'date' => '2026-10-22',
            'payee_name' => 'Boulangerie',
            'category_id' => null,
            'memo' => null,
            'amount' => -1_000,
            'cleared' => false,
            ...$overrides,
        ];
    }

    private function url(string $name, ?Transaction $transaction = null): string
    {
        return route($name, array_filter(['current_team' => $this->team, 'transaction' => $transaction]));
    }
}
