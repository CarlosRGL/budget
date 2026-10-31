<?php

namespace Tests\Feature\BudgetScreen;

use App\Enums\MoneyMoveKind;
use App\Models\MoneyMove;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignMoneyTest extends TestCase
{
    use InteractsWithBudget, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();
    }

    public function test_assigning_creates_the_assignment_and_logs_the_move()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');

        $this->put(route('budget.assignment.update', $this->params(['month' => '2026-10', 'category' => $groceries])), ['amount' => 65_000])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(65_000, $this->assignedIn($groceries, '2026-10'));
        $move = MoneyMove::sole();
        $this->assertSame(MoneyMoveKind::Assign, $move->kind);
        $this->assertNull($move->from_category_id);
        $this->assertSame($groceries->id, $move->to_category_id);
        $this->assertSame(65_000, $move->amount);
        $this->assertSame($this->user->id, $move->user_id);
        $this->assertSame('2026-10-01', $move->month->toDateString());
        $this->assertSame($this->team->id, $move->team_id);
    }

    public function test_lowering_an_assignment_logs_money_going_back_to_ready_to_assign()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $this->assign($groceries, '2026-10', 65_000);

        $this->put(route('budget.assignment.update', $this->params(['month' => '2026-10', 'category' => $groceries])), ['amount' => -1_000])
            ->assertSessionHasNoErrors();

        $this->assertSame(-1_000, $this->assignedIn($groceries, '2026-10'));
        $move = MoneyMove::sole();
        $this->assertSame($groceries->id, $move->from_category_id);
        $this->assertNull($move->to_category_id);
        $this->assertSame(66_000, $move->amount);
    }

    public function test_an_unchanged_assignment_is_not_logged()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $this->assign($groceries, '2026-10', 65_000);

        $this->put(route('budget.assignment.update', $this->params(['month' => '2026-10', 'category' => $groceries])), ['amount' => 65_000])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, MoneyMove::count());
    }

    public function test_the_amount_must_be_whole_cents()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');

        $this->put(route('budget.assignment.update', $this->params(['month' => '2026-10', 'category' => $groceries])), ['amount' => '12.5'])
            ->assertSessionHasErrors('amount');
        $this->put(route('budget.assignment.update', $this->params(['month' => '2026-10', 'category' => $groceries])), [])
            ->assertSessionHasErrors('amount');
    }

    public function test_money_moves_between_two_categories()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $restaurant = $this->category($groceries->group, 'Restaurant', sort: 1);
        $this->assign($groceries, '2026-10', 65_000);

        $this->post(route('budget.moves.store', $this->params(['month' => '2026-10'])), [
            'from_category_id' => $groceries->id,
            'to_category_id' => $restaurant->id,
            'amount' => 5_000,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(60_000, $this->assignedIn($groceries, '2026-10'));
        $this->assertSame(5_000, $this->assignedIn($restaurant, '2026-10'));
        $move = MoneyMove::sole();
        $this->assertSame(MoneyMoveKind::Move, $move->kind);
        $this->assertSame($groceries->id, $move->from_category_id);
        $this->assertSame($restaurant->id, $move->to_category_id);
        $this->assertSame(5_000, $move->amount);
        $this->assertSame($this->user->id, $move->user_id);
    }

    public function test_money_moves_to_and_from_ready_to_assign()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $this->assign($groceries, '2026-10', 65_000);

        $this->post(route('budget.moves.store', $this->params(['month' => '2026-10'])), [
            'from_category_id' => $groceries->id,
            'to_category_id' => null,
            'amount' => 15_000,
        ])->assertSessionHasNoErrors();
        $this->post(route('budget.moves.store', $this->params(['month' => '2026-10'])), [
            'from_category_id' => null,
            'to_category_id' => $groceries->id,
            'amount' => 1_000,
        ])->assertSessionHasNoErrors();

        $this->assertSame(51_000, $this->assignedIn($groceries, '2026-10'));
        $this->assertSame(2, MoneyMove::where('kind', MoneyMoveKind::Move)->count());
    }

    public function test_a_move_needs_a_positive_amount_and_two_different_ends()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $url = route('budget.moves.store', $this->params(['month' => '2026-10']));

        $this->post($url, ['from_category_id' => null, 'to_category_id' => null, 'amount' => 100])->assertSessionHasErrors('to_category_id');
        $this->post($url, ['from_category_id' => $groceries->id, 'to_category_id' => $groceries->id, 'amount' => 100])->assertSessionHasErrors('to_category_id');
        $this->post($url, ['from_category_id' => $groceries->id, 'to_category_id' => null, 'amount' => 0])->assertSessionHasErrors('amount');
        $this->post($url, ['from_category_id' => $groceries->id, 'to_category_id' => null, 'amount' => -5])->assertSessionHasErrors('amount');

        $this->assertSame(0, MoneyMove::count());
    }
}
