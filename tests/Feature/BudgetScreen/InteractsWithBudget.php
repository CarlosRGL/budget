<?php

namespace Tests\Feature\BudgetScreen;

use App\Models\Account;
use App\Models\Assignment;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;

/**
 * A member signed in to their household, with helpers to fill its budget.
 */
trait InteractsWithBudget
{
    protected User $user;

    protected Team $team;

    protected Account $checking;

    protected function signIn(string $name = 'Alex'): void
    {
        $this->user = User::factory()->create(['name' => $name]);
        $this->team = $this->user->currentTeam;
        $this->checking = Account::factory()->for($this->team)->create(['on_budget' => true]);
        $this->actingAs($this->user);
    }

    protected function group(string $name, int $sort = 0, ?Team $team = null): CategoryGroup
    {
        return CategoryGroup::factory()->for($team ?? $this->team)->create(['name' => $name, 'sort' => $sort]);
    }

    protected function category(CategoryGroup $group, string $name, int $sort = 0): Category
    {
        return Category::factory()->for($group->team)->for($group, 'group')->create(['name' => $name, 'sort' => $sort]);
    }

    protected function income(int $amount, string $date = '2026-10-01'): Transaction
    {
        return Transaction::factory()->for($this->team)->for($this->checking)->inflowToReadyToAssign($amount)->create(['date' => $date]);
    }

    protected function spend(Category $category, int $amount, string $date = '2026-10-15'): Transaction
    {
        return Transaction::factory()->for($this->team)->for($this->checking)->create([
            'category_id' => $category->id,
            'amount' => -$amount,
            'date' => $date,
        ]);
    }

    protected function assign(Category $category, string $month, int $amount): Assignment
    {
        return Assignment::factory()->for($category->team)->for($category)->create(['month' => $month.'-01', 'amount' => $amount]);
    }

    protected function assignedIn(Category $category, string $month): int
    {
        return (int) Assignment::withoutGlobalScopes()
            ->where('category_id', $category->id)
            ->where('month', $month.'-01')
            ->value('amount');
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected function params(array $parameters = []): array
    {
        return ['current_team' => $this->team, ...$parameters];
    }
}
