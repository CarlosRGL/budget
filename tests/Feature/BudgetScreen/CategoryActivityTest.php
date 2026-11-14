<?php

namespace Tests\Feature\BudgetScreen;

use App\Models\Account;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryActivityTest extends TestCase
{
    use InteractsWithBudget, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();
    }

    public function test_it_lists_the_transactions_and_split_lines_behind_the_activity()
    {
        $courses = $this->category($this->group('Courant'), 'Courses');
        $other = $this->category($this->group('Loisirs'), 'Sorties');
        $leclerc = Payee::factory()->for($this->team)->create(['name' => 'Leclerc']);
        $this->spend($courses, 2000, '2026-10-05')->update(['payee_id' => $leclerc->id, 'memo' => 'Plein']);
        $split = $this->spend($other, 5000, '2026-10-12');
        $split->update(['category_id' => null]);
        $split->splits()->createMany([
            ['category_id' => $courses->id, 'amount' => -3000, 'memo' => 'Partie courses'],
            ['category_id' => $other->id, 'amount' => -2000],
        ]);
        $this->spend($courses, 999, '2026-09-30');
        $offBudget = Account::factory()->for($this->team)->create(['on_budget' => false]);
        Transaction::factory()->for($this->team)->for($offBudget)->create(['category_id' => $courses->id, 'amount' => -100, 'date' => '2026-10-10']);

        $this->getJson(route('budget.activity', $this->params(['month' => '2026-10', 'category' => $courses])))
            ->assertOk()
            ->assertJsonCount(2, 'transactions')
            ->assertJsonPath('transactions.0.date', '2026-10-12')
            ->assertJsonPath('transactions.0.amount', -3000)
            ->assertJsonPath('transactions.0.memo', 'Partie courses')
            ->assertJsonPath('transactions.1.payee', 'Leclerc')
            ->assertJsonPath('transactions.1.amount', -2000);
    }

    public function test_another_households_category_is_not_found()
    {
        $stranger = User::factory()->create();
        $foreign = $this->category($this->group('X', team: $stranger->currentTeam), 'Y');

        $this->getJson(route('budget.activity', $this->params(['month' => '2026-10', 'category' => $foreign])))
            ->assertNotFound();
    }
}
