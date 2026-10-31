<?php

namespace Tests\Feature\BudgetScreen;

use App\Enums\MoneyMoveKind;
use App\Models\Assignment;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\MoneyMove;
use App\Models\Payee;
use App\Models\Target;
use App\Models\TargetSnooze;
use App\Models\Team;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManageCategoriesTest extends TestCase
{
    use InteractsWithBudget, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();
    }

    public function test_a_group_is_created_at_the_end()
    {
        $this->group('Quotidien', sort: 3);

        $this->post(route('budget.groups.store', $this->params()), ['name' => 'Épargne'])
            ->assertSessionHasNoErrors()
            ->assertRedirect()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Group “Épargne” added.']);

        $group = CategoryGroup::where('name', 'Épargne')->sole();
        $this->assertSame(4, $group->sort);
        $this->assertSame($this->team->id, $group->team_id);

        $this->post(route('budget.groups.store', $this->params()), ['name' => ''])->assertSessionHasErrors('name');
    }

    public function test_a_group_is_renamed_hidden_and_shown_again()
    {
        $group = $this->group('Quotidien');
        $url = route('budget.groups.update', $this->params(['group' => $group]));

        $this->patch($url, ['name' => 'Vie courante'])->assertSessionHasNoErrors();
        $this->patch($url, ['hidden' => true])->assertSessionHasNoErrors();

        $group->refresh();
        $this->assertSame('Vie courante', $group->name);
        $this->assertTrue($group->hidden);

        $this->patch($url, ['hidden' => false])->assertSessionHasNoErrors();
        $this->assertFalse($group->refresh()->hidden);

        $this->patch($url, ['name' => ''])->assertSessionHasErrors('name');
    }

    public function test_only_an_empty_group_can_be_deleted()
    {
        $full = $this->group('Quotidien');
        $this->category($full, 'Courses');
        $empty = $this->group('Divers');

        $this->delete(route('budget.groups.destroy', $this->params(['group' => $full])))
            ->assertStatus(422)
            ->assertSee('This group still has categories', false);
        $this->delete(route('budget.groups.destroy', $this->params(['group' => $empty])))
            ->assertRedirect();

        $this->assertSame([$full->id], CategoryGroup::pluck('id')->all());
    }

    public function test_a_category_is_created_at_the_end_of_its_group()
    {
        $group = $this->group('Quotidien');
        $this->category($group, 'Courses', sort: 2);

        $this->post(route('budget.categories.store', $this->params(['group' => $group])), ['name' => '🍕 Restaurant'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $category = Category::where('name', '🍕 Restaurant')->sole();
        $this->assertSame($group->id, $category->category_group_id);
        $this->assertSame(3, $category->sort);
        $this->assertSame($this->team->id, $category->team_id);
    }

    public function test_a_category_is_renamed_annotated_and_hidden()
    {
        $category = $this->category($this->group('Quotidien'), 'Courses');
        $url = route('budget.categories.update', $this->params(['category' => $category]));

        $this->patch($url, ['name' => 'Courses Leclerc'])->assertSessionHasNoErrors();
        $this->patch($url, ['note' => 'Marché le samedi'])->assertSessionHasNoErrors();
        $this->patch($url, ['hidden' => true])->assertSessionHasNoErrors();

        $category->refresh();
        $this->assertSame('Courses Leclerc', $category->name);
        $this->assertSame('Marché le samedi', $category->note);
        $this->assertTrue($category->hidden);

        $this->patch($url, ['note' => ''])->assertSessionHasNoErrors();
        $this->assertNull($category->refresh()->note);
    }

    public function test_deleting_a_category_moves_everything_to_its_replacement()
    {
        $group = $this->group('Quotidien');
        $old = $this->category($group, 'Supermarché');
        $groceries = $this->category($group, 'Courses', sort: 1);
        $other = $this->category($group, 'Essence', sort: 2);

        $transaction = $this->spend($old, 4_000);
        $split = TransactionSplit::factory()->for($this->spend($other, 3_000))->create(['category_id' => $old->id]);
        $payee = Payee::factory()->for($this->team)->create(['last_category_id' => $old->id]);
        $this->assign($old, '2026-09', 10_000);
        $this->assign($old, '2026-10', 20_000);
        $this->assign($groceries, '2026-10', 65_000);
        Target::factory()->for($this->team)->for($old)->create();
        TargetSnooze::create(['category_id' => $old->id, 'month' => '2026-10-01']);
        $toOld = MoneyMove::factory()->for($this->team)->create(['to_category_id' => $old->id, 'kind' => MoneyMoveKind::Assign]);
        MoneyMove::factory()->for($this->team)->create(['from_category_id' => $old->id, 'to_category_id' => $groceries->id, 'kind' => MoneyMoveKind::Move]);

        $this->delete(route('budget.categories.destroy', $this->params(['category' => $old])), ['replacement_category_id' => $groceries->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNull(Category::find($old->id));
        $this->assertSame($groceries->id, $transaction->refresh()->category_id);
        $this->assertSame($groceries->id, $split->refresh()->category_id);
        $this->assertSame($groceries->id, $payee->refresh()->last_category_id);
        $this->assertSame(10_000, $this->assignedIn($groceries, '2026-09'));
        $this->assertSame(85_000, $this->assignedIn($groceries, '2026-10'));
        $this->assertSame(2, Assignment::count());
        $this->assertSame(0, Target::count());
        $this->assertSame(0, TargetSnooze::count());
        $this->assertSame($groceries->id, $toOld->refresh()->to_category_id);
        $this->assertSame(1, MoneyMove::count());
    }

    public function test_deleting_a_category_needs_another_category_of_the_household()
    {
        $old = $this->category($this->group('Quotidien'), 'Supermarché');
        $theirs = $this->category($this->group('Autre', team: $this->otherTeam()), 'Leurs courses');
        $url = route('budget.categories.destroy', $this->params(['category' => $old]));

        $this->delete($url)->assertSessionHasErrors('replacement_category_id');
        $this->delete($url, ['replacement_category_id' => $old->id])->assertSessionHasErrors('replacement_category_id');
        $this->delete($url, ['replacement_category_id' => $theirs->id])->assertSessionHasErrors('replacement_category_id');

        $this->assertNotNull(Category::find($old->id));
    }

    public function test_groups_and_categories_are_reordered_and_categories_can_change_group()
    {
        $daily = $this->group('Quotidien', sort: 0);
        $bills = $this->group('Factures', sort: 1);
        $groceries = $this->category($daily, 'Courses', sort: 0);
        $fuel = $this->category($daily, 'Essence', sort: 1);
        $rent = $this->category($bills, 'Loyer', sort: 0);

        $this->put(route('budget.order', $this->params()), ['groups' => [
            ['group_id' => $bills->id, 'category_ids' => [$fuel->id, $rent->id]],
            ['group_id' => $daily->id, 'category_ids' => [$groceries->id]],
        ]])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame([0, 1], [$bills->refresh()->sort, $daily->refresh()->sort]);
        $this->assertSame([$bills->id, 0], [$fuel->refresh()->category_group_id, $fuel->sort]);
        $this->assertSame([$bills->id, 1], [$rent->refresh()->category_group_id, $rent->sort]);
        $this->assertSame([$daily->id, 0], [$groceries->refresh()->category_group_id, $groceries->sort]);
    }

    public function test_the_order_must_list_every_group_and_category_of_the_household_once()
    {
        $daily = $this->group('Quotidien', sort: 0);
        $groceries = $this->category($daily, 'Courses');
        $fuel = $this->category($daily, 'Essence', sort: 1);
        $theirs = $this->category($this->group('Autre', team: $this->otherTeam()), 'Leurs courses');
        $url = route('budget.order', $this->params());

        $this->put($url, ['groups' => [['group_id' => $daily->id, 'category_ids' => [$groceries->id]]]])
            ->assertSessionHasErrors('groups');
        $this->put($url, ['groups' => [['group_id' => $daily->id, 'category_ids' => [$groceries->id, $fuel->id, $fuel->id]]]])
            ->assertSessionHasErrors();
        $this->put($url, ['groups' => [['group_id' => $daily->id, 'category_ids' => [$groceries->id, $fuel->id, $theirs->id]]]])
            ->assertSessionHasErrors();
        $this->put($url, ['groups' => [
            ['group_id' => $daily->id, 'category_ids' => [$groceries->id, $fuel->id]],
            ['group_id' => $theirs->category_group_id, 'category_ids' => [$theirs->id]],
        ]])->assertSessionHasErrors();

        $this->assertNotSame($daily->id, $theirs->refresh()->category_group_id);
        $this->assertSame(1, $fuel->refresh()->sort);
    }

    private function otherTeam(): Team
    {
        return User::factory()->create()->currentTeam;
    }
}
