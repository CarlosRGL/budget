<?php

namespace Tests\Feature\Budget;

use App\Budget\SpendingReport;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SpendingReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $checking;

    private Category $courses;

    private Category $couches;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00'));
        $this->user = User::factory()->create();
        $team = $this->user->currentTeam;
        $this->checking = Account::factory()->for($team)->create(['type' => AccountType::Checking]);
        $group = CategoryGroup::factory()->for($team)->create(['name' => 'Courant']);
        $this->courses = Category::factory()->for($team)->create(['category_group_id' => $group->id, 'name' => 'Courses']);
        $this->couches = Category::factory()->for($team)->create(['category_group_id' => $group->id, 'name' => 'Couches']);
    }

    public function test_spending_and_income_follow_the_budget_rules(): void
    {
        $team = $this->user->currentTeam;
        $salary = Payee::factory()->for($team)->create(['name' => 'Salaire Alex']);

        // Starting balance: neither income nor spending.
        Transaction::factory()->for($team)->for($this->checking)->create(['amount' => 100_000, 'date' => '2026-09-01', 'is_starting_balance' => true, 'inflow_to_rta' => true]);
        Transaction::factory()->for($team)->for($this->checking)->inflowToReadyToAssign(245_000)->create(['date' => '2026-10-01', 'payee_id' => $salary->id]);
        Transaction::factory()->for($team)->for($this->checking)->create(['amount' => -12_000, 'date' => '2026-09-10', 'category_id' => $this->courses->id]);
        // A refund lowers the spending of its category.
        Transaction::factory()->for($team)->for($this->checking)->create(['amount' => 2_000, 'date' => '2026-09-12', 'category_id' => $this->courses->id]);
        $split = Transaction::factory()->for($team)->for($this->checking)->create(['amount' => -10_000, 'date' => '2026-10-05', 'category_id' => null]);
        $split->splits()->createMany([
            ['category_id' => $this->courses->id, 'amount' => -7_000],
            ['category_id' => $this->couches->id, 'amount' => -3_000],
        ]);
        Transaction::factory()->for($team)->for($this->checking)->create(['amount' => -500, 'date' => '2026-10-06', 'category_id' => null]);
        // A transfer between budget accounts is not spending.
        $savings = Account::factory()->for($team)->create(['type' => AccountType::Savings]);
        Transaction::createTransfer($this->checking, $savings, 40_000, CarbonImmutable::parse('2026-10-10'));

        $report = app(SpendingReport::class)->forPeriod($team, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-01'));

        $spending = collect($report['spending'])->mapWithKeys(fn (array $row) => ["{$row['categoryId']}|{$row['month']}" => $row['amount']]);
        $this->assertSame(10_000, $spending["{$this->courses->id}|2026-09"]);
        $this->assertSame(7_000, $spending["{$this->courses->id}|2026-10"]);
        $this->assertSame(3_000, $spending["{$this->couches->id}|2026-10"]);
        $this->assertSame(['2026-10' => 500], $report['uncategorized']);
        $this->assertSame([['payee' => 'Salaire Alex', 'month' => '2026-10', 'amount' => 245_000]], $report['income']);
    }

    public function test_the_page_filters_the_period_and_the_accounts(): void
    {
        $team = $this->user->currentTeam;
        $cash = Account::factory()->for($team)->create(['type' => AccountType::Cash]);
        Transaction::factory()->for($team)->for($this->checking)->create(['amount' => -1_000, 'date' => '2026-10-02', 'category_id' => $this->courses->id]);
        Transaction::factory()->for($team)->for($cash)->create(['amount' => -2_000, 'date' => '2026-10-03', 'category_id' => $this->courses->id]);
        Transaction::factory()->for($team)->for($cash)->create(['amount' => -4_000, 'date' => '2026-03-03', 'category_id' => $this->courses->id]);

        $this->actingAs($this->user)
            ->get("/{$team->slug}/stats?from=2026-09&to=2026-10&accounts={$cash->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('stats/show')
                ->where('months', ['2026-09', '2026-10'])
                ->where('selectedAccounts', [$cash->id])
                ->where('spending', [['categoryId' => $this->courses->id, 'month' => '2026-10', 'amount' => 2_000]]));

        $this->actingAs($this->user)
            ->get("/{$team->slug}/stats")
            ->assertInertia(fn (Assert $page) => $page
                ->where('from', '2026-05')
                ->where('to', '2026-10')
                ->where('selectedAccounts', null));
    }
}
