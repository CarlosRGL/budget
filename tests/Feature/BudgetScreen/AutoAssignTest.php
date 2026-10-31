<?php

namespace Tests\Feature\BudgetScreen;

use App\Enums\MoneyMoveKind;
use App\Enums\TargetType;
use App\Models\Category;
use App\Models\MoneyMove;
use App\Models\Target;
use App\Models\TargetSnooze;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoAssignTest extends TestCase
{
    use InteractsWithBudget, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();
    }

    public function test_underfunded_targets_are_funded_in_order_within_ready_to_assign()
    {
        $daily = $this->group('Quotidien', sort: 0);
        $savings = $this->group('Épargne', sort: 1);
        $groceries = $this->category($daily, 'Courses', sort: 1);
        $fuel = $this->category($daily, 'Essence', sort: 0);
        $paused = $this->category($daily, 'Cadeaux', sort: 2);
        $holidays = $this->category($savings, 'Vacances');
        $this->target($groceries, TargetType::RefillUpTo, 65_000);
        $this->target($fuel, TargetType::SetAside, 20_000);
        $this->target($paused, TargetType::SetAside, 5_000);
        $this->target($holidays, TargetType::SetAside, 30_000);
        TargetSnooze::create(['category_id' => $paused->id, 'month' => '2026-10-01']);
        $this->income(70_000);
        $this->assign($groceries, '2026-10', 10_000);

        $this->post(route('budget.auto-assign', $this->params(['month' => '2026-10'])), ['strategy' => 'underfunded'])
            ->assertSessionHasNoErrors()
            ->assertRedirect()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '€600.00 assigned to 2 categories.']);

        $this->assertSame(20_000, $this->assignedIn($fuel, '2026-10'));
        $this->assertSame(50_000, $this->assignedIn($groceries, '2026-10'));
        $this->assertSame(0, $this->assignedIn($holidays, '2026-10'));
        $this->assertSame(0, $this->assignedIn($paused, '2026-10'));

        $moves = MoneyMove::orderBy('id')->get();
        $this->assertCount(2, $moves);
        $this->assertSame([MoneyMoveKind::AutoAssign, MoneyMoveKind::AutoAssign], $moves->pluck('kind')->all());
        $this->assertSame([$fuel->id, $groceries->id], $moves->pluck('to_category_id')->all());
        $this->assertSame([20_000, 40_000], $moves->pluck('amount')->all());
        $this->assertSame([null, null], $moves->pluck('from_category_id')->all());
        $this->assertSame($this->user->id, $moves->first()->user_id);
    }

    public function test_nothing_is_funded_without_ready_to_assign()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $this->target($groceries, TargetType::SetAside, 20_000);

        $this->post(route('budget.auto-assign', $this->params(['month' => '2026-10'])), ['strategy' => 'underfunded'])
            ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'Nothing to assign.']);

        $this->assertSame(0, $this->assignedIn($groceries, '2026-10'));
        $this->assertSame(0, MoneyMove::count());
    }

    public function test_last_month_assignments_are_copied()
    {
        $daily = $this->group('Quotidien');
        $groceries = $this->category($daily, 'Courses');
        $fuel = $this->category($daily, 'Essence', sort: 1);
        $rent = $this->category($daily, 'Loyer', sort: 2);
        $this->assign($groceries, '2026-09', 65_000);
        $this->assign($groceries, '2026-10', 5_000);
        $this->assign($fuel, '2026-10', 10_000);
        $this->assign($rent, '2026-09', 90_000);
        $this->assign($rent, '2026-10', 90_000);

        $this->post(route('budget.auto-assign', $this->params(['month' => '2026-10'])), ['strategy' => 'last_month'])
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '€500.00 assigned to 2 categories.']);

        $this->assertSame(65_000, $this->assignedIn($groceries, '2026-10'));
        $this->assertSame(0, $this->assignedIn($fuel, '2026-10'));
        $this->assertSame(90_000, $this->assignedIn($rent, '2026-10'));

        $moves = MoneyMove::orderBy('id')->get();
        $this->assertCount(2, $moves);
        $this->assertSame([$groceries->id, null], $moves->pluck('to_category_id')->all());
        $this->assertSame([null, $fuel->id], $moves->pluck('from_category_id')->all());
        $this->assertSame([60_000, 10_000], $moves->pluck('amount')->all());
        $this->assertSame([MoneyMoveKind::AutoAssign, MoneyMoveKind::AutoAssign], $moves->pluck('kind')->all());
    }

    public function test_average_assignments_are_applied()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $this->assign($groceries, '2026-08', 60_000);
        $this->assign($groceries, '2026-09', 70_000);

        $this->post(route('budget.auto-assign', $this->params(['month' => '2026-10'])), ['strategy' => 'average'])
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '€650.00 assigned to 1 category.']);

        $this->assertSame(65_000, $this->assignedIn($groceries, '2026-10'));
    }

    public function test_reset_sets_every_assignment_of_the_month_to_zero()
    {
        $daily = $this->group('Quotidien');
        $groceries = $this->category($daily, 'Courses');
        $fuel = $this->category($daily, 'Essence', sort: 1);
        $this->assign($groceries, '2026-10', 65_000);
        $this->assign($fuel, '2026-10', 123_456);
        $this->assign($fuel, '2026-11', 10_000);

        $this->post(route('budget.auto-assign', $this->params(['month' => '2026-10'])), ['strategy' => 'reset'])
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '€1,884.56 returned to Ready to Assign from 2 categories.']);

        $this->assertSame(0, $this->assignedIn($groceries, '2026-10'));
        $this->assertSame(0, $this->assignedIn($fuel, '2026-10'));
        $this->assertSame(10_000, $this->assignedIn($fuel, '2026-11'));
        $this->assertSame(2, MoneyMove::where('kind', MoneyMoveKind::Reset)->whereNull('to_category_id')->count());
    }

    public function test_only_the_selected_categories_are_changed()
    {
        $daily = $this->group('Quotidien');
        $groceries = $this->category($daily, 'Courses');
        $fuel = $this->category($daily, 'Essence', sort: 1);
        $rent = $this->category($daily, 'Loyer', sort: 2);
        $this->assign($groceries, '2026-10', 65_000);
        $this->assign($fuel, '2026-10', 20_000);
        $this->assign($rent, '2026-10', 90_000);

        $this->post(route('budget.auto-assign', $this->params(['month' => '2026-10'])), [
            'strategy' => 'reset',
            'category_ids' => [$groceries->id, $fuel->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->assignedIn($groceries, '2026-10'));
        $this->assertSame(0, $this->assignedIn($fuel, '2026-10'));
        $this->assertSame(90_000, $this->assignedIn($rent, '2026-10'));
    }

    public function test_another_households_categories_cannot_be_selected()
    {
        $foreign = Category::factory()->create();

        $this->post(route('budget.auto-assign', $this->params(['month' => '2026-10'])), [
            'strategy' => 'reset',
            'category_ids' => [$foreign->id],
        ])->assertSessionHasErrors('category_ids.0');
    }

    public function test_the_strategy_must_be_known()
    {
        $this->post(route('budget.auto-assign', $this->params(['month' => '2026-10'])), ['strategy' => 'magic'])
            ->assertSessionHasErrors('strategy');
    }

    private function target(Category $category, TargetType $type, int $amount): Target
    {
        return Target::factory()->for($this->team)->for($category)->create(['type' => $type, 'amount' => $amount]);
    }
}
