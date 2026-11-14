<?php

namespace Tests\Feature\BudgetScreen;

use App\Enums\MoneyMoveKind;
use App\Models\MoneyMove;
use App\Models\TargetSnooze;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Undo and redo on the budget screen: put back the assigned amounts and paused
 * targets of a month, only if nobody changed them since.
 */
class RestoreBudgetTest extends TestCase
{
    use InteractsWithBudget, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();
    }

    public function test_it_puts_back_the_amounts_and_logs_an_undo()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $fuel = $this->category($this->group('Quotidien'), 'Essence');
        $this->assign($groceries, '2026-10', 40_000);
        $this->assign($fuel, '2026-10', 20_000);

        $this->restore([
            'kind' => 'undo',
            'assignments' => [
                ['category_id' => $groceries->id, 'from' => 40_000, 'to' => 50_000],
                ['category_id' => $fuel->id, 'from' => 20_000, 'to' => 10_000],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(50_000, $this->assignedIn($groceries, '2026-10'));
        $this->assertSame(10_000, $this->assignedIn($fuel, '2026-10'));
        $this->assertSame([MoneyMoveKind::Undo, MoneyMoveKind::Undo], MoneyMove::pluck('kind')->all());
    }

    public function test_a_redo_is_logged_as_such()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');

        $this->restore([
            'kind' => 'redo',
            'assignments' => [['category_id' => $groceries->id, 'from' => 0, 'to' => 30_000]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(30_000, $this->assignedIn($groceries, '2026-10'));
        $this->assertSame(MoneyMoveKind::Redo, MoneyMove::sole()->kind);
    }

    public function test_it_pauses_and_resumes_targets()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $fuel = $this->category($this->group('Quotidien'), 'Essence');
        TargetSnooze::create(['category_id' => $fuel->id, 'month' => '2026-10-01']);

        $this->restore([
            'kind' => 'undo',
            'snoozes' => [
                ['category_id' => $groceries->id, 'from' => false, 'to' => true],
                ['category_id' => $fuel->id, 'from' => true, 'to' => false],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame([$groceries->id], TargetSnooze::pluck('category_id')->all());
    }

    public function test_nothing_changes_when_a_value_changed_since()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $fuel = $this->category($this->group('Quotidien'), 'Essence');
        $this->assign($groceries, '2026-10', 40_000);
        $this->assign($fuel, '2026-10', 25_000);

        $this->restore([
            'kind' => 'undo',
            'assignments' => [
                ['category_id' => $groceries->id, 'from' => 40_000, 'to' => 50_000],
                ['category_id' => $fuel->id, 'from' => 20_000, 'to' => 10_000],
            ],
            'snoozes' => [['category_id' => $groceries->id, 'from' => false, 'to' => true]],
        ])->assertSessionHasErrors('restore');

        $this->assertSame(40_000, $this->assignedIn($groceries, '2026-10'));
        $this->assertSame(25_000, $this->assignedIn($fuel, '2026-10'));
        $this->assertSame(0, TargetSnooze::count());
        $this->assertSame(0, MoneyMove::count());
    }

    public function test_a_paused_target_changed_since_is_a_conflict_too()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        TargetSnooze::create(['category_id' => $groceries->id, 'month' => '2026-10-01']);

        $this->restore([
            'kind' => 'undo',
            'snoozes' => [['category_id' => $groceries->id, 'from' => false, 'to' => true]],
        ])->assertSessionHasErrors('restore');
    }

    public function test_another_households_category_is_refused()
    {
        $other = Team::factory()->create();
        $theirs = $this->category($this->group('Ailleurs', team: $other), 'Courses');

        $this->restore([
            'kind' => 'undo',
            'assignments' => [['category_id' => $theirs->id, 'from' => 0, 'to' => 10_000]],
        ])->assertSessionHasErrors('assignments.0.category_id');

        $this->assertSame(0, $this->assignedIn($theirs, '2026-10'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function restore(array $data): TestResponse
    {
        return $this->put(route('budget.restore', $this->params(['month' => '2026-10'])), $data);
    }
}
