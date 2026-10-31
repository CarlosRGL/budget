<?php

namespace Tests\Feature\Budget;

use App\Budget\BudgetCalculator;
use App\Budget\MonthBudget;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Assignment;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Team;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Account $checking;

    private CategoryGroup $group;

    private BudgetCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        // Past months show the Ready to Assign of the current month: pin "now".
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00'));

        $this->team = Team::factory()->create();
        $this->checking = Account::factory()->for($this->team)->create(['type' => AccountType::Checking]);
        $this->group = CategoryGroup::factory()->for($this->team)->create();
        $this->calculator = app(BudgetCalculator::class);
    }

    public function test_unspent_money_carries_over_to_the_next_month()
    {
        $courses = $this->category('Courses');
        $this->income(200_000, '2026-10-01');
        $this->assign($courses, '2026-10', 65_000);
        $this->spend($courses, 60_000, '2026-10-12');

        $october = $this->month('2026-10')->category($courses);
        $november = $this->month('2026-11')->category($courses);

        $this->assertSame(65_000, $october->assigned);
        $this->assertSame(-60_000, $october->activity);
        $this->assertSame(5_000, $october->available);
        $this->assertSame(5_000, $november->carryover);
        $this->assertSame(5_000, $november->available);
    }

    public function test_overspending_does_not_carry_over_and_is_taken_from_next_months_ready_to_assign()
    {
        $courses = $this->category('Courses');
        $this->income(200_000, '2026-10-01');
        $this->assign($courses, '2026-10', 65_000);
        $this->spend($courses, 67_156, '2026-10-20');

        $october = $this->month('2026-10');
        $november = $this->month('2026-11');

        $this->assertSame(-2_156, $october->category($courses)->available);
        $this->assertSame(200_000 - 65_000, $october->readyToAssign);
        $this->assertSame(0, $november->category($courses)->carryover);
        $this->assertSame(0, $november->category($courses)->available);
        $this->assertSame(200_000 - 65_000 - 2_156, $november->readyToAssign);
    }

    public function test_assigning_in_a_future_month_reduces_ready_to_assign_now()
    {
        $vacances = $this->category('Vacances');
        $this->income(100_000, '2026-10-01');
        $this->assign($vacances, '2026-12', 20_000);

        $this->assertSame(80_000, $this->month('2026-10')->readyToAssign);
        $this->assertSame(0, $this->month('2026-10')->category($vacances)->available);
    }

    public function test_a_past_month_shows_the_ready_to_assign_of_the_current_month()
    {
        $vacances = $this->category('Vacances');
        $this->income(100_000, '2026-08-01');
        $this->income(100_000, '2026-09-01');
        $this->income(100_000, '2026-10-01');
        $this->assign($vacances, '2026-08', 150_000);
        $this->assign($vacances, '2026-10', 50_000);

        $this->assertSame(100_000, $this->month('2026-10')->readyToAssign);
        $this->assertSame(100_000, $this->month('2026-08')->readyToAssign);
        $this->assertSame(150_000, $this->month('2026-08')->category($vacances)->available);
    }

    public function test_income_and_starting_balances_feed_ready_to_assign()
    {
        Transaction::factory()->for($this->team)->for($this->checking)->create([
            'amount' => 149_675, 'date' => '2026-10-01', 'is_starting_balance' => true, 'inflow_to_rta' => true,
        ]);
        $this->income(250_000, '2026-10-28');
        $this->income(300_000, '2026-11-28');

        $this->assertSame(149_675 + 250_000, $this->month('2026-10')->readyToAssign);
        $this->assertSame(149_675 + 250_000 + 300_000, $this->month('2026-11')->readyToAssign);
    }

    public function test_a_split_transaction_spreads_its_activity_over_its_categories()
    {
        $courses = $this->category('Courses');
        $couches = $this->category('Couches');

        $purchase = Transaction::factory()->for($this->team)->for($this->checking)->create([
            'amount' => -10_000, 'date' => '2026-10-05', 'category_id' => null,
        ]);
        $purchase->splits()->createMany([
            ['category_id' => $courses->id, 'amount' => -7_000],
            ['category_id' => $couches->id, 'amount' => -3_000],
        ]);

        $october = $this->month('2026-10');

        $this->assertSame(-7_000, $october->category($courses)->activity);
        $this->assertSame(-3_000, $october->category($couches)->activity);
        $this->assertSame(0, $october->uncategorizedActivity);
    }

    public function test_a_split_line_can_go_to_ready_to_assign()
    {
        $courses = $this->category('Courses');

        $deposit = Transaction::factory()->for($this->team)->for($this->checking)->create([
            'amount' => 50_000, 'date' => '2026-10-05', 'category_id' => null,
        ]);
        $deposit->splits()->createMany([
            ['inflow_to_rta' => true, 'amount' => 45_000],
            ['category_id' => $courses->id, 'amount' => 5_000],
        ]);

        $october = $this->month('2026-10');

        $this->assertSame(45_000, $october->readyToAssign);
        $this->assertSame(5_000, $october->category($courses)->activity);
    }

    public function test_a_transfer_between_budget_accounts_changes_nothing_in_the_budget()
    {
        $courses = $this->category('Courses');
        $savings = Account::factory()->for($this->team)->create(['type' => AccountType::Savings]);
        $this->income(100_000, '2026-10-01');
        $this->assign($courses, '2026-10', 30_000);

        Transaction::createTransfer($this->checking, $savings, 40_000, CarbonImmutable::parse('2026-10-10'));

        $october = $this->month('2026-10');

        $this->assertSame(70_000, $october->readyToAssign);
        $this->assertSame(30_000, $october->category($courses)->available);
        $this->assertSame(0, $october->uncategorizedActivity);
    }

    public function test_a_categorized_transfer_to_an_off_budget_account_is_spending()
    {
        $credit = $this->category('Crédit Maison');
        $loan = Account::factory()->for($this->team)->create(['type' => AccountType::Loan]);
        $this->assign($credit, '2026-10', 93_740);

        Transaction::createTransfer($this->checking, $loan, 93_740, CarbonImmutable::parse('2026-10-05'), category: $credit);

        $october = $this->month('2026-10');

        $this->assertSame(-93_740, $october->category($credit)->activity);
        $this->assertSame(0, $october->category($credit)->available);
    }

    public function test_off_budget_accounts_never_reach_the_budget()
    {
        $courses = $this->category('Courses');
        $pea = Account::factory()->for($this->team)->create(['type' => AccountType::Investment]);

        Transaction::factory()->for($this->team)->for($pea)->create(['amount' => 500_000, 'date' => '2026-10-01', 'inflow_to_rta' => true]);
        Transaction::factory()->for($this->team)->for($pea)->create(['amount' => -1_000, 'date' => '2026-10-02', 'category_id' => $courses->id]);
        Transaction::factory()->for($this->team)->for($pea)->create(['amount' => -2_000, 'date' => '2026-10-03', 'category_id' => null]);

        $october = $this->month('2026-10');

        $this->assertSame(0, $october->readyToAssign);
        $this->assertSame(0, $october->category($courses)->activity);
        $this->assertSame(0, $october->uncategorizedActivity);
    }

    public function test_uncategorized_spending_is_reported_and_touches_no_envelope()
    {
        $courses = $this->category('Courses');
        $this->income(100_000, '2026-10-01');
        $this->assign($courses, '2026-10', 30_000);
        Transaction::factory()->for($this->team)->for($this->checking)->create(['amount' => -39_613, 'date' => '2026-10-19', 'category_id' => null]);

        $october = $this->month('2026-10');

        $this->assertSame(-39_613, $october->uncategorizedActivity);
        $this->assertSame(30_000, $october->category($courses)->available);
        $this->assertSame(70_000, $october->readyToAssign);
    }

    public function test_unapproved_transactions_count_exactly_like_approved_ones()
    {
        $courses = $this->category('Courses');
        $this->assign($courses, '2026-10', 30_000);
        Transaction::factory()->for($this->team)->for($this->checking)->create(['amount' => -1_000, 'date' => '2026-10-19', 'category_id' => $courses->id, 'approved_at' => null]);
        Transaction::factory()->for($this->team)->for($this->checking)->approved()->create(['amount' => -2_000, 'date' => '2026-10-19', 'category_id' => $courses->id]);

        $this->assertSame(-3_000, $this->month('2026-10')->category($courses)->activity);
    }

    public function test_editing_an_old_transaction_recomputes_the_following_months()
    {
        $courses = $this->category('Courses');
        $this->income(300_000, '2026-08-01');
        $this->assign($courses, '2026-08', 10_000);
        $spending = $this->spend($courses, 4_000, '2026-08-10');

        $this->assertSame(6_000, $this->month('2026-11')->category($courses)->available);

        $spending->update(['amount' => -15_000]);

        $this->assertSame(0, $this->month('2026-11')->category($courses)->available);
        $this->assertSame(300_000 - 10_000 - 5_000, $this->month('2026-11')->readyToAssign);
    }

    public function test_group_totals_add_up_their_categories()
    {
        $courses = $this->category('Courses');
        $gasolina = $this->category('Gasolina');
        $this->assign($courses, '2026-10', 65_000);
        $this->assign($gasolina, '2026-10', 20_000);
        $this->spend($gasolina, 17_239, '2026-10-26');

        $totals = $this->month('2026-10')->group($this->group);

        $this->assertSame(85_000, $totals->assigned);
        $this->assertSame(-17_239, $totals->activity);
        $this->assertSame(67_761, $totals->available);
    }

    public function test_another_households_data_is_ignored()
    {
        $courses = $this->category('Courses');
        $other = Team::factory()->create();
        $otherAccount = Account::factory()->for($other)->create();
        Transaction::factory()->for($other)->for($otherAccount)->inflowToReadyToAssign(999_999)->create(['date' => '2026-10-01']);
        Assignment::factory()->for($other)->create(['month' => '2026-10-01', 'amount' => 50_000]);

        $october = $this->month('2026-10');

        $this->assertSame(0, $october->readyToAssign);
        $this->assertSame(0, $october->category($courses)->assigned);
        $this->assertCount(1, $october->categories);
    }

    public function test_category_detail_gives_last_month_and_averages()
    {
        $courses = $this->category('Courses');
        $this->assign($courses, '2026-08', 60_000);
        $this->spend($courses, 50_000, '2026-08-10');
        $this->assign($courses, '2026-09', 70_000);
        $this->spend($courses, 80_000, '2026-09-10');
        $this->assign($courses, '2026-10', 65_000);

        $detail = $this->calculator->categoryDetail($this->team, $courses, CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(70_000, $detail->assignedLastMonth);
        $this->assertSame(80_000, $detail->spentLastMonth);
        $this->assertSame(65_000, $detail->averageAssigned);
        $this->assertSame(65_000, $detail->averageSpent);
        $this->assertSame(65_000, $detail->month->assigned);
    }

    public function test_category_details_give_every_category_detail_at_once()
    {
        $courses = $this->category('Courses');
        $vacances = $this->category('Vacances');
        $this->assign($courses, '2026-09', 70_000);
        $this->spend($courses, 80_000, '2026-09-10');
        $this->assign($vacances, '2026-09', 20_000);

        $month = CarbonImmutable::parse('2026-10-01');
        $details = $this->calculator->categoryDetails($this->team, $month);

        $this->assertEquals($this->calculator->categoryDetail($this->team, $courses, $month), $details[$courses->id]);
        $this->assertSame(20_000, $details[$vacances->id]->assignedLastMonth);
        $this->assertSame(0, $details[$vacances->id]->spentLastMonth);
    }

    public function test_the_first_and_last_days_belong_to_their_month()
    {
        $courses = $this->category('Courses');
        $this->income(10_000, '2026-10-31');
        $this->spend($courses, 1_000, '2026-10-01');
        $this->spend($courses, 2_000, '2026-10-31');
        $this->spend($courses, 4_000, '2026-11-01');
        Transaction::factory()->for($this->team)->for($this->checking)->create(['amount' => -500, 'date' => '2026-10-31', 'category_id' => null]);

        $october = $this->month('2026-10');

        $this->assertSame(-3_000, $october->category($courses)->activity);
        $this->assertSame(-500, $october->uncategorizedActivity);
        $this->assertSame(10_000 - 0, $october->readyToAssign);
        $this->assertSame(10_000 - 3_000, $this->month('2026-11')->readyToAssign);
    }

    public function test_a_month_without_any_data_is_empty()
    {
        $courses = $this->category('Courses');

        $october = $this->month('2026-10');

        $this->assertSame(0, $october->readyToAssign);
        $this->assertSame(0, $october->category($courses)->available);
    }

    public function test_it_computes_three_years_of_a_busy_household_quickly()
    {
        $categories = Category::factory()->count(50)->for($this->team)->for($this->group, 'group')->create();
        $rows = [];
        $assignments = [];
        $start = CarbonImmutable::parse('2024-01-01');

        for ($m = 0; $m < 36; $m++) {
            $month = $start->addMonths($m);
            foreach ($categories as $category) {
                $assignments[] = ['team_id' => $this->team->id, 'category_id' => $category->id, 'month' => $month->toDateString(), 'amount' => 10_000, 'created_at' => now(), 'updated_at' => now()];
                for ($t = 0; $t < 3; $t++) {
                    $rows[] = ['team_id' => $this->team->id, 'account_id' => $this->checking->id, 'category_id' => $category->id, 'date' => $month->addDays($t * 9)->toDateString(), 'amount' => -3_000, 'cleared' => false, 'is_starting_balance' => false, 'inflow_to_rta' => false, 'created_at' => now(), 'updated_at' => now()];
                }
            }
        }
        foreach (array_chunk($assignments, 500) as $chunk) {
            Assignment::withoutGlobalScopes()->insert($chunk);
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Transaction::withoutGlobalScopes()->insert($chunk);
        }

        $started = microtime(true);
        $month = $this->month('2026-12');
        $elapsedMs = (microtime(true) - $started) * 1000;

        $this->assertSame(1_000 * 36, $month->category($categories->first())->available);
        $this->assertLessThan(200, $elapsedMs);
    }

    private function category(string $name): Category
    {
        return Category::factory()->for($this->team)->for($this->group, 'group')->create(['name' => $name]);
    }

    private function income(int $amount, string $date): Transaction
    {
        return Transaction::factory()->for($this->team)->for($this->checking)->inflowToReadyToAssign($amount)->create(['date' => $date]);
    }

    private function spend(Category $category, int $amount, string $date): Transaction
    {
        return Transaction::factory()->for($this->team)->for($this->checking)->create([
            'amount' => -$amount, 'date' => $date, 'category_id' => $category->id,
        ]);
    }

    private function assign(Category $category, string $month, int $amount): Assignment
    {
        return Assignment::factory()->for($this->team)->for($category)->create(['month' => $month.'-01', 'amount' => $amount]);
    }

    private function month(string $month): MonthBudget
    {
        return $this->calculator->forMonth($this->team, CarbonImmutable::parse($month.'-01'));
    }
}
