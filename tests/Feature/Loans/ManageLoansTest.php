<?php

namespace Tests\Feature\Loans;

use App\Actions\Accounts\CreateAccount;
use App\Enums\AccountType;
use App\Enums\TargetType;
use App\Loans\LoanReport;
use App\Models\Account;
use App\Models\Category;
use App\Models\Loan;
use App\Models\Target;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ManageLoansTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00'));
        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
        $this->actingAs($this->user);
    }

    public function test_a_loan_with_two_lines_is_created_and_shows_where_it_stands(): void
    {
        $account = $this->loanAccount();

        $this->post(route('loans.store', $this->team), $this->mortgage(['account_id' => $account->id]))
            ->assertSessionHasNoErrors();

        $loan = Loan::sole();
        $this->assertCount(2, $loan->parts);

        $this->get(route('loans.show', [$this->team, $loan]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('loans/show')
                ->where('loan.remaining', 190_331_49 + 7_833_55)
                ->where('loan.payment', 904_07 + 33_33)
                ->where('loan.nextPaymentDate', '2026-11-05')
                ->where('loan.endDate', '2046-05-05')
                ->where('loan.account.id', $account->id)
                ->has('months', 300)
                ->where('months.0.month', '2021-06'));
    }

    public function test_the_index_lists_the_loans(): void
    {
        $this->post(route('loans.store', $this->team), $this->mortgage());

        $this->get(route('loans.index', $this->team))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('loans/index')
                ->has('loans', 1)
                ->where('loans.0.name', 'Crédit immobilier'));
    }

    public function test_updating_replaces_the_lines(): void
    {
        $this->post(route('loans.store', $this->team), $this->mortgage());
        $loan = Loan::sole();

        $data = $this->mortgage(['name' => 'Maison']);
        array_pop($data['parts']);
        $this->put(route('loans.update', [$this->team, $loan]), $data)->assertSessionHasNoErrors();

        $this->assertSame('Maison', $loan->refresh()->name);
        $this->assertCount(1, $loan->parts);
    }

    public function test_the_linked_account_is_brought_to_the_remaining_capital(): void
    {
        $account = $this->loanAccount();
        $this->post(route('loans.store', $this->team), $this->mortgage(['account_id' => $account->id]));

        $this->post(route('loans.sync-account', [$this->team, Loan::sole()]))->assertSessionHasNoErrors();

        $this->assertSame(-(190_331_49 + 7_833_55), (int) $account->transactions()->sum('amount'));
    }

    public function test_linking_a_category_gives_it_the_monthly_payment_as_target(): void
    {
        $category = Category::factory()->for($this->team)->create(['name' => 'Crédit Maison']);

        $this->post(route('loans.store', $this->team), $this->mortgage(['category_id' => $category->id]))->assertSessionHasNoErrors();

        $target = Target::sole();
        $this->assertSame($category->id, $target->category_id);
        $this->assertSame(TargetType::RefillUpTo, $target->type);
        $this->assertSame(904_07 + 33_33, $target->amount);
    }

    public function test_the_payments_found_in_the_category_are_checked_month_by_month(): void
    {
        $category = Category::factory()->for($this->team)->create();
        $checking = app(CreateAccount::class)->handle($this->user, 'Compte joint', AccountType::Checking, true, 5_000_00, CarbonImmutable::parse('2026-08-01'));
        $this->post(route('loans.store', $this->team), $this->mortgage(['category_id' => $category->id]));
        $this->spend($checking, $category, '2026-09-05', 900_00);
        $this->spend($checking, $category, '2026-10-05', 937_40);

        $this->get(route('loans.show', [$this->team, Loan::sole()]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('payments', 3)
                ->where('payments.0.month', '2026-10')
                ->where('payments.0.status', 'paid')
                ->where('payments.0.paid', 937_40)
                ->has('payments.0.transactions', 1)
                ->where('payments.1.status', 'different')
                ->where('payments.1.paid', 900_00)
                ->where('payments.2.month', '2026-08')
                ->where('payments.2.status', 'missing'));
    }

    public function test_the_capital_of_each_passed_due_date_is_posted_to_the_loan_account_once(): void
    {
        $account = $this->loanAccount();
        $this->post(route('loans.store', $this->team), $this->mortgage(['account_id' => $account->id]));
        $loan = Loan::sole();
        $this->post(route('loans.sync-account', [$this->team, $loan]));

        $this->travelTo(CarbonImmutable::parse('2026-11-06 05:00'));
        $this->artisan('loans:post-payments')->assertSuccessful();
        $this->artisan('loans:post-payments')->assertSuccessful();

        $posted = Transaction::query()->where('account_id', $account->id)->whereHas('payee', fn ($q) => $q->where('name', 'Loan payment'))->get();
        $this->assertCount(1, $posted);
        $this->assertSame('2026-11-05', $posted->sole()->date->toDateString());
        $this->assertSame(
            -LoanReport::remaining($loan->load('parts'), CarbonImmutable::today()),
            (int) $account->transactions()->sum('amount'),
        );
    }

    public function test_a_newly_linked_account_starts_counting_without_posting(): void
    {
        $this->post(route('loans.store', $this->team), $this->mortgage());
        $loan = Loan::sole();
        $loan->update(['account_id' => $this->loanAccount()->id]);

        $this->travelTo(CarbonImmutable::parse('2026-12-06'));
        $this->artisan('loans:post-payments');
        $this->assertSame('2026-12-06', $loan->refresh()->posted_through?->toDateString());
        $this->assertSame(0, Transaction::query()->whereHas('payee', fn ($q) => $q->where('name', 'Loan payment'))->count());
    }

    public function test_a_loan_needs_valid_lines(): void
    {
        $this->post(route('loans.store', $this->team), ['name' => 'Vide', 'parts' => []])->assertSessionHasErrors('parts');
        $this->post(route('loans.store', $this->team), $this->mortgage(['parts' => [['label' => 'X', 'principal' => 0, 'rate_milli' => 1000, 'months' => 0, 'first_payment_date' => 'nope']]]))
            ->assertSessionHasErrors(['parts.0.principal', 'parts.0.months', 'parts.0.first_payment_date']);
    }

    public function test_another_households_loans_and_accounts_cannot_be_used(): void
    {
        $stranger = User::factory()->create();
        $foreignLoan = Loan::factory()->for($stranger->currentTeam)->create();
        $foreignAccount = Account::factory()->for($stranger->currentTeam)->create();

        $this->get(route('loans.show', [$this->team, $foreignLoan]))->assertNotFound();
        $this->delete(route('loans.destroy', [$this->team, $foreignLoan]))->assertNotFound();
        $this->post(route('loans.store', $this->team), $this->mortgage(['account_id' => $foreignAccount->id]))->assertSessionHasErrors('account_id');
    }

    public function test_a_loan_can_be_deleted(): void
    {
        $this->post(route('loans.store', $this->team), $this->mortgage());

        $this->delete(route('loans.destroy', [$this->team, Loan::sole()]))->assertRedirect(route('loans.index', $this->team));

        $this->assertSame(0, Loan::count());
    }

    private function spend(Account $account, Category $category, string $date, int $amount): void
    {
        Transaction::create([
            'account_id' => $account->id,
            'date' => $date,
            'category_id' => $category->id,
            'amount' => -$amount,
            'cleared' => true,
        ]);
    }

    private function loanAccount(): Account
    {
        return app(CreateAccount::class)->handle($this->user, 'Prêt immobilier', AccountType::Loan, false, -139_450_00, CarbonImmutable::parse('2026-10-01'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mortgage(array $overrides = []): array
    {
        return [
            'name' => 'Crédit immobilier',
            'account_id' => null,
            'parts' => [
                ['label' => 'Prêt principal', 'principal' => 235_900_00, 'rate_milli' => 1140, 'months' => 300, 'first_payment_date' => '2021-06-05', 'payment' => null],
                ['label' => 'Prêt à taux zéro', 'principal' => 10_000_00, 'rate_milli' => 0, 'months' => 300, 'first_payment_date' => '2021-06-05', 'payment' => null],
            ],
            ...$overrides,
        ];
    }
}
