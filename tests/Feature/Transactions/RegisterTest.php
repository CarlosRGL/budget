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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegisterTest extends TestCase
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

    public function test_an_account_page_lists_its_transactions_with_its_balances()
    {
        $courses = Category::factory()->for($this->team)->create(['name' => 'Courses']);
        $leclerc = Payee::factory()->for($this->team)->create(['name' => 'Leclerc']);
        Transaction::factory()->for($this->team)->for($this->checking)->approved()->create(['amount' => 100_000, 'cleared' => true, 'date' => '2026-10-01', 'inflow_to_rta' => true]);
        Transaction::factory()->for($this->team)->for($this->checking)->approved()->create(['amount' => -18_09, 'cleared' => false, 'date' => '2026-10-22', 'payee_id' => $leclerc->id, 'category_id' => $courses->id]);
        Transaction::factory()->for($this->team)->create(['amount' => -5_000]);

        $this->get(route('accounts.show', ['current_team' => $this->team, 'account' => $this->checking]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('accounts/show')
                ->where('register.balances', ['cleared' => 100_000, 'uncleared' => -18_09, 'working' => 100_000 - 18_09])
                ->has('register.transactions', 2)
                ->where('register.transactions.0.payee.name', 'Leclerc')
                ->where('register.transactions.0.category.name', 'Courses')
                ->where('register.transactions.0.amount', -18_09)
                ->where('register.transactions.1.inflowToRta', true)
                ->has('pickers.payees')
                ->has('pickers.categoryGroups')
                ->has('pickers.accounts'));
    }

    public function test_the_inbox_filter_keeps_unapproved_or_uncategorized_transactions()
    {
        $courses = Category::factory()->for($this->team)->create();
        $savings = Account::factory()->for($this->team)->create(['type' => AccountType::Savings]);

        Transaction::factory()->for($this->team)->for($this->checking)->approved()->create(['category_id' => $courses->id, 'memo' => 'done']);
        Transaction::factory()->for($this->team)->for($this->checking)->create(['category_id' => $courses->id, 'approved_at' => null, 'memo' => 'to approve']);
        Transaction::factory()->for($this->team)->for($this->checking)->approved()->create(['category_id' => null, 'memo' => 'needs category']);
        Transaction::createTransfer($this->checking, $savings, 1_000, now(), $this->user);
        Transaction::query()->update(['approved_at' => now()]);
        Transaction::where('memo', 'to approve')->update(['approved_at' => null]);

        $this->get(route('accounts.show', ['current_team' => $this->team, 'account' => $this->checking, 'filter' => 'inbox']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('register.filter', 'inbox')
                ->has('register.transactions', 2)
                ->where('register.transactions', fn ($transactions) => collect($transactions)->pluck('memo')->sort()->values()->all() === ['needs category', 'to approve'])
                ->where('inboxCount', 2));
    }

    public function test_all_accounts_lists_every_transaction_with_its_account()
    {
        $savings = Account::factory()->for($this->team)->create(['name' => 'Livret A', 'type' => AccountType::Savings]);
        Transaction::factory()->for($this->team)->for($this->checking)->create();
        Transaction::factory()->for($this->team)->for($savings)->create();

        $this->get(route('accounts.index', ['current_team' => $this->team]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('accounts/index')
                ->has('register.transactions', 2)
                ->has('register.transactions.0.account.name'));
    }
}
