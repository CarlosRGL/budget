<?php

namespace Tests\Feature\SimpleView;

use App\Actions\Accounts\CreateAccount;
use App\Enums\AccountType;
use App\Models\Category;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ViewerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $viewer;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00'));
        $this->owner = User::factory()->create();
        $this->team = $this->owner->currentTeam;
        $this->viewer = User::factory()->create();
        $this->team->members()->attach($this->viewer->id, ['role' => 'viewer']);
        $this->viewer->forceFill(['current_team_id' => $this->team->id])->save();
    }

    public function test_a_viewer_lands_on_the_month_overview_instead_of_the_budget(): void
    {
        $this->actingAs($this->viewer);

        $this->get(route('budget.show', ['current_team' => $this->team]))->assertRedirect(route('simple.overview', ['current_team' => $this->team]));
        $this->get(route('accounts.index', ['current_team' => $this->team]))->assertRedirect(route('simple.overview', ['current_team' => $this->team]));
        $this->get(route('stats.show', ['current_team' => $this->team]))->assertOk();
        $this->get(route('loans.index', ['current_team' => $this->team]))->assertOk();
    }

    public function test_a_viewer_cannot_change_anything(): void
    {
        $category = Category::factory()->for($this->team)->create();
        $this->actingAs($this->viewer);

        $this->put(route('budget.assignment.update', [$this->team, '2026-10', $category]), ['amount' => 100_00])->assertForbidden();
        $this->post(route('loans.store', ['current_team' => $this->team]), [])->assertForbidden();
    }

    public function test_the_overview_shows_planned_spent_and_left_per_category(): void
    {
        $this->actingAs($this->owner);
        $category = Category::factory()->for($this->team)->create(['name' => 'Courses']);
        $account = app(CreateAccount::class)->handle($this->owner, 'Compte joint', AccountType::Checking, true, 1_000_00, CarbonImmutable::parse('2026-10-01'));
        $this->put(route('budget.assignment.update', [$this->team, '2026-10', $category]), ['amount' => 400_00]);
        Transaction::create(['account_id' => $account->id, 'date' => '2026-10-12', 'category_id' => $category->id, 'amount' => -150_00, 'cleared' => true]);

        $this->actingAs($this->viewer)
            ->get(route('simple.overview', ['current_team' => $this->team]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('simple/overview')
                ->where('groups.0.categories.0.name', 'Courses')
                ->where('groups.0.categories.0.planned', 400_00)
                ->where('groups.0.categories.0.spent', 150_00)
                ->where('groups.0.categories.0.left', 250_00)
                ->where('recent.0.amount', -150_00));

        $this->get(route('simple.spending', ['current_team' => $this->team]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('simple/spending')
                ->where('transactions.0.categories', ['Courses']));
    }

    public function test_other_members_keep_the_full_budget(): void
    {
        $this->actingAs($this->owner)->get(route('budget.show', ['current_team' => $this->team]))->assertOk();
    }
}
