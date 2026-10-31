<?php

namespace Tests\Feature\Budget;

use App\Budget\BudgetCalculator;
use App\Budget\MonthBudget;
use App\Budget\TargetCalculator;
use App\Budget\TargetProgress;
use App\Enums\TargetType;
use App\Models\Account;
use App\Models\Assignment;
use App\Models\Category;
use App\Models\Target;
use App\Models\TargetSnooze;
use App\Models\Team;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TargetCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Account $checking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->checking = Account::factory()->for($this->team)->create();
    }

    public function test_refill_up_to_only_asks_for_what_is_missing()
    {
        $courses = $this->categoryWithTarget(TargetType::RefillUpTo, 65_000);
        $this->assign($courses, '2026-09', 65_000);
        $this->spend($courses, 60_000, '2026-09-15');

        $progress = $this->progress($courses, '2026-10');

        $this->assertSame(60_000, $progress->needed);
        $this->assertSame(0, $progress->funded);
        $this->assertSame(60_000, $progress->toGo);
        $this->assertTrue($progress->isUnderfunded());
    }

    public function test_refill_up_to_is_met_once_the_missing_part_is_assigned()
    {
        $courses = $this->categoryWithTarget(TargetType::RefillUpTo, 65_000);
        $this->assign($courses, '2026-09', 65_000);
        $this->spend($courses, 60_000, '2026-09-15');
        $this->assign($courses, '2026-10', 60_000);

        $progress = $this->progress($courses, '2026-10');

        $this->assertSame(0, $progress->toGo);
        $this->assertFalse($progress->isUnderfunded());
        $this->assertFalse($progress->isOverfunded());
        $this->assertSame(1.0, $progress->ratio);
    }

    public function test_set_aside_asks_for_the_full_amount_every_month_and_accumulates()
    {
        $vacances = $this->categoryWithTarget(TargetType::SetAside, 20_000);
        $this->assign($vacances, '2026-09', 20_000);

        $progress = $this->progress($vacances, '2026-10');

        $this->assertSame(20_000, $progress->needed);
        $this->assertSame(20_000, $progress->toGo);
    }

    public function test_assigning_more_than_needed_is_overfunded()
    {
        $vacances = $this->categoryWithTarget(TargetType::SetAside, 20_000);
        $this->assign($vacances, '2026-10', 25_000);

        $progress = $this->progress($vacances, '2026-10');

        $this->assertTrue($progress->isOverfunded());
        $this->assertSame(0, $progress->toGo);
    }

    public function test_weekly_targets_count_the_weekdays_of_the_month()
    {
        // October 2026 has five Thursdays (1, 8, 15, 22, 29) and four Mondays.
        $thursday = $this->categoryWithTarget(TargetType::Weekly, 1_000, ['weekday' => 4]);
        $monday = $this->categoryWithTarget(TargetType::Weekly, 1_000, ['weekday' => 1]);

        $this->assertSame(5_000, $this->progress($thursday, '2026-10')->needed);
        $this->assertSame(4_000, $this->progress($monday, '2026-10')->needed);
    }

    public function test_by_date_spreads_what_is_missing_over_the_remaining_months()
    {
        // 873,34 € for the insurance due in March: Oct, Nov, Dec, Jan, Feb, Mar = 6 months.
        $maaf = $this->categoryWithTarget(TargetType::ByDate, 87_334, ['due_date' => '2027-03-15']);
        $this->assign($maaf, '2026-09', 30_000);

        $progress = $this->progress($maaf, '2026-10');

        // (87 334 - 30 000) / 6 = 9 555,67 → rounded up so the target is reached in time.
        $this->assertSame(9_556, $progress->needed);
        $this->assertEqualsWithDelta(30_000 / 87_334, $progress->ratio, 0.0001);
    }

    public function test_by_date_in_the_due_month_asks_for_everything_left()
    {
        $maaf = $this->categoryWithTarget(TargetType::ByDate, 87_334, ['due_date' => '2026-10-20']);
        $this->assign($maaf, '2026-09', 80_000);

        $this->assertSame(7_334, $this->progress($maaf, '2026-10')->needed);
    }

    public function test_a_yearly_by_date_target_rolls_over_to_next_year_once_due()
    {
        $maaf = $this->categoryWithTarget(TargetType::ByDate, 120_000, ['due_date' => '2026-03-15', 'repeat' => 'yearly']);

        // Next due date is 2027-03-15: Oct → Mar = 6 months.
        $this->assertSame(20_000, $this->progress($maaf, '2026-10')->needed);
    }

    public function test_a_snoozed_target_needs_nothing_this_month()
    {
        $courses = $this->categoryWithTarget(TargetType::RefillUpTo, 65_000);
        TargetSnooze::create(['category_id' => $courses->id, 'month' => '2026-10-01']);

        $progress = $this->progress($courses, '2026-10');

        $this->assertTrue($progress->snoozed);
        $this->assertSame(0, $progress->needed);
        $this->assertFalse($progress->isUnderfunded());
        $this->assertSame(65_000, $this->progress($courses, '2026-11')->needed);
    }

    public function test_targets_are_computed_for_a_whole_month_at_once()
    {
        $courses = $this->categoryWithTarget(TargetType::RefillUpTo, 65_000);
        $vacances = $this->categoryWithTarget(TargetType::SetAside, 20_000);
        $this->categoryWithoutTarget();

        $all = app(TargetCalculator::class)->forMonth($this->month('2026-10'));

        $this->assertCount(2, $all);
        $this->assertSame(65_000, $all[$courses->id]->needed);
        $this->assertSame(20_000, $all[$vacances->id]->needed);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function categoryWithTarget(TargetType $type, int $amount, array $attributes = []): Category
    {
        $category = $this->categoryWithoutTarget();
        Target::factory()->for($this->team)->for($category)->create(['type' => $type, 'amount' => $amount, ...$attributes]);

        return $category;
    }

    private function categoryWithoutTarget(): Category
    {
        return Category::factory()->for($this->team)->create();
    }

    private function assign(Category $category, string $month, int $amount): void
    {
        Assignment::factory()->for($this->team)->for($category)->create(['month' => $month.'-01', 'amount' => $amount]);
    }

    private function spend(Category $category, int $amount, string $date): void
    {
        Transaction::factory()->for($this->team)->for($this->checking)->create(['amount' => -$amount, 'date' => $date, 'category_id' => $category->id]);
    }

    private function month(string $month): MonthBudget
    {
        return app(BudgetCalculator::class)->forMonth($this->team, CarbonImmutable::parse($month.'-01'));
    }

    private function progress(Category $category, string $month): TargetProgress
    {
        return app(TargetCalculator::class)->forMonth($this->month($month))[$category->id];
    }
}
