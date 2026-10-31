<?php

namespace Tests\Feature\BudgetScreen;

use App\Models\Assignment;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\MoneyMove;
use App\Models\Target;
use App\Models\TargetSnooze;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BudgetIsolationTest extends TestCase
{
    use InteractsWithBudget, RefreshDatabase;

    private Category $theirs;

    private CategoryGroup $theirGroup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();
        $stranger = User::factory()->create();
        $this->theirGroup = $this->group('Leur groupe', team: $stranger->currentTeam);
        $this->theirs = $this->category($this->theirGroup, 'Leurs courses');
        $this->assign($this->theirs, '2026-10', 50_000);
    }

    public function test_another_household_category_or_group_is_not_found()
    {
        $category = $this->params(['category' => $this->theirs]);
        $inMonth = $this->params(['month' => '2026-10', 'category' => $this->theirs]);
        $group = $this->params(['group' => $this->theirGroup]);

        $this->put(route('budget.assignment.update', $inMonth), ['amount' => 0])->assertNotFound();
        $this->post(route('budget.snooze.store', $inMonth))->assertNotFound();
        $this->delete(route('budget.snooze.destroy', $inMonth))->assertNotFound();
        $this->put(route('budget.target.update', $category), ['type' => 'set_aside', 'amount' => 100])->assertNotFound();
        $this->delete(route('budget.target.destroy', $category))->assertNotFound();
        $this->patch(route('budget.categories.update', $category), ['name' => 'Piraté'])->assertNotFound();
        $this->delete(route('budget.categories.destroy', $category), ['replacement_category_id' => $this->theirs->id])->assertNotFound();
        $this->post(route('budget.categories.store', $group), ['name' => 'Piraté'])->assertNotFound();
        $this->patch(route('budget.groups.update', $group), ['name' => 'Piraté'])->assertNotFound();
        $this->delete(route('budget.groups.destroy', $group))->assertNotFound();

        $this->assertSame('Leurs courses', $this->theirs->refresh()->name);
        $this->assertSame('Leur groupe', $this->theirGroup->refresh()->name);
        $this->assertSame(50_000, $this->assignedIn($this->theirs, '2026-10'));
        $this->assertSame(0, Target::withoutGlobalScopes()->count());
        $this->assertSame(0, TargetSnooze::count());
    }

    public function test_money_cannot_move_to_or_from_another_household_category()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $url = route('budget.moves.store', $this->params(['month' => '2026-10']));

        $this->post($url, ['from_category_id' => $this->theirs->id, 'to_category_id' => $groceries->id, 'amount' => 100])
            ->assertSessionHasErrors('from_category_id');
        $this->post($url, ['from_category_id' => $groceries->id, 'to_category_id' => $this->theirs->id, 'amount' => 100])
            ->assertSessionHasErrors('to_category_id');

        $this->assertSame(50_000, $this->assignedIn($this->theirs, '2026-10'));
        $this->assertSame(0, MoneyMove::withoutGlobalScopes()->count());
    }

    public function test_the_budget_and_auto_assign_ignore_other_households()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');

        $url = route('budget.show', $this->params(['month' => '2026-10', 'category' => $this->theirs->id]));
        $response = $this->get($url);
        $response->assertInertia(fn (Assert $page) => $page
            ->has('groups', 1)
            ->where('groups.0.categories.0.id', $groceries->id)
            ->where('readyToAssign', 0));

        $this->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $response->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'budget/show',
            'X-Inertia-Partial-Data' => 'detail',
        ])->assertNotFound();

        $this->post(route('budget.auto-assign', $this->params(['month' => '2026-10'])), ['strategy' => 'reset']);

        $this->assertSame(50_000, $this->assignedIn($this->theirs, '2026-10'));
        $this->assertSame(1, Assignment::withoutGlobalScopes()->count());
    }

    public function test_a_household_url_cannot_reach_a_household_the_member_does_not_belong_to()
    {
        $this->get(route('budget.show', ['current_team' => $this->theirs->team]))->assertForbidden();
    }
}
