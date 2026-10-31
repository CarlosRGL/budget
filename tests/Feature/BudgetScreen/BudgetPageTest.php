<?php

namespace Tests\Feature\BudgetScreen;

use App\Budget\BudgetCalculator;
use App\Enums\MoneyMoveKind;
use App\Enums\TargetType;
use App\Enums\TeamRole;
use App\Models\MoneyMove;
use App\Models\MonthNote;
use App\Models\Target;
use App\Models\TargetSnooze;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class BudgetPageTest extends TestCase
{
    use InteractsWithBudget, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->signIn();
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        auth()->logout();

        $this->get(route('budget.show', $this->params()))->assertRedirect(route('login'));
    }

    public function test_the_budget_shows_the_current_month_by_default()
    {
        $this->get(route('budget.show', $this->params()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('budget/show')
                ->where('month', '2026-10')
                ->where('previousMonth', '2026-09')
                ->where('nextMonth', '2026-11')
                ->where('note', null)
                ->missing('detail'));
    }

    public function test_a_malformed_month_is_not_found()
    {
        foreach (['2026-13', '2026-1', 'octobre', '2026-10-01'] as $month) {
            $this->get("/{$this->team->slug}/budget/{$month}")->assertNotFound();
        }
    }

    public function test_the_dashboard_redirects_to_the_budget()
    {
        $this->get(route('dashboard', $this->params()))
            ->assertRedirect(route('budget.show', $this->params()));
    }

    public function test_the_budget_lists_groups_and_categories_in_order_with_their_figures()
    {
        $bills = $this->group('Factures', sort: 1);
        $daily = $this->group('Quotidien', sort: 0);
        $rent = $this->category($bills, 'Loyer');
        $groceries = $this->category($daily, 'Courses', sort: 1);
        $fuel = $this->category($daily, 'Essence', sort: 0);
        $groceries->update(['note' => 'Marché le samedi']);
        $fuel->update(['hidden' => true]);

        $this->income(300_000);
        $this->assign($groceries, '2026-09', 65_000);
        $this->spend($groceries, 60_000, '2026-09-10');
        $this->assign($groceries, '2026-10', 60_000);
        $this->spend($groceries, 70_000);
        $this->assign($rent, '2026-10', 90_000);
        $this->checking->transactions()->create([
            'team_id' => $this->team->id, 'date' => '2026-10-05', 'amount' => -1_500,
        ]);

        $this->get(route('budget.show', $this->params(['month' => '2026-10'])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('readyToAssign', 300_000 - 65_000 - 60_000 - 90_000)
                ->where('uncategorizedActivity', -1_500)
                ->where('overspentCount', 1)
                ->where('underfundedCount', 0)
                ->has('groups', 2)
                ->where('groups.0.name', 'Quotidien')
                ->where('groups.0.hidden', false)
                ->where('groups.0.assigned', 60_000)
                ->where('groups.0.activity', -70_000)
                ->where('groups.0.available', -5_000)
                ->where('groups.0.categories.0.name', 'Essence')
                ->where('groups.0.categories.0.hidden', true)
                ->where('groups.0.categories.0.target', null)
                ->where('groups.0.categories.1', [
                    'id' => $groceries->id,
                    'name' => 'Courses',
                    'hidden' => false,
                    'note' => 'Marché le samedi',
                    'carryover' => 5_000,
                    'assigned' => 60_000,
                    'activity' => -70_000,
                    'available' => -5_000,
                    'target' => null,
                ])
                ->where('groups.1.name', 'Factures')
                ->where('groups.1.categories.0.available', 90_000));
    }

    public function test_categories_show_their_target_progress()
    {
        $daily = $this->group('Quotidien');
        $groceries = $this->category($daily, 'Courses');
        $holidays = $this->category($daily, 'Vacances', sort: 1);
        $insurance = $this->category($daily, 'MAAF', sort: 2);
        Target::factory()->for($this->team)->for($groceries)->create(['type' => TargetType::RefillUpTo, 'amount' => 65_000]);
        Target::factory()->for($this->team)->for($holidays)->create(['type' => TargetType::SetAside, 'amount' => 20_000]);
        Target::factory()->for($this->team)->for($insurance)->create([
            'type' => TargetType::ByDate, 'amount' => 60_000, 'due_date' => '2027-03-01', 'repeat' => 'yearly',
        ]);
        TargetSnooze::create(['category_id' => $holidays->id, 'month' => '2026-10-01']);
        $this->assign($groceries, '2026-09', 5_000);
        $this->assign($groceries, '2026-10', 10_000);

        $this->get(route('budget.show', $this->params(['month' => '2026-10'])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('underfundedCount', 2)
                ->where('groups.0.categories.0.target', [
                    'type' => 'refill_up_to',
                    'amount' => 65_000,
                    'weekday' => null,
                    'dueDate' => null,
                    'repeat' => null,
                    'needed' => 60_000,
                    'funded' => 10_000,
                    'toGo' => 50_000,
                    'ratio' => 10_000 / 60_000,
                    'snoozed' => false,
                    'underfunded' => true,
                    'overfunded' => false,
                ])
                ->where('groups.0.categories.1.target.snoozed', true)
                ->where('groups.0.categories.1.target.underfunded', false)
                ->where('groups.0.categories.2.target.dueDate', '2027-03-01')
                ->where('groups.0.categories.2.target.repeat', 'yearly')
                ->where('groups.0.categories.2.target.needed', 10_000));
    }

    public function test_the_month_note_and_money_moves_are_shown_newest_first()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $rent = $this->category($this->group('Factures'), 'Loyer');
        MonthNote::create(['month' => '2026-10-01', 'note' => 'Prime de fin d’année']);
        $first = MoneyMove::factory()->for($this->team)->create([
            'month' => '2026-10-01', 'to_category_id' => $groceries->id, 'amount' => 65_000,
            'kind' => MoneyMoveKind::Assign, 'user_id' => $this->user->id, 'created_at' => '2026-10-02 09:00:00',
        ]);
        $second = MoneyMove::factory()->for($this->team)->create([
            'month' => '2026-10-01', 'from_category_id' => $groceries->id, 'to_category_id' => $rent->id,
            'amount' => 5_000, 'kind' => MoneyMoveKind::Move, 'created_at' => '2026-10-03 09:00:00',
        ]);
        MoneyMove::factory()->for($this->team)->create(['month' => '2026-09-01']);

        $this->get(route('budget.show', $this->params(['month' => '2026-10'])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('note', 'Prime de fin d’année')
                ->has('moves', 2)
                ->where('moves.0', [
                    'id' => $second->id,
                    'kind' => 'move',
                    'amount' => 5_000,
                    'from' => ['id' => $groceries->id, 'name' => 'Courses'],
                    'to' => ['id' => $rent->id, 'name' => 'Loyer'],
                    'user' => null,
                    'at' => $second->created_at->toIso8601String(),
                ])
                ->where('moves.1.id', $first->id)
                ->where('moves.1.from', null)
                ->where('moves.1.user', 'Alex'));
    }

    public function test_the_month_budget_is_computed_once_per_request()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        Target::factory()->for($this->team)->for($groceries)->create();
        $this->partialMock(BudgetCalculator::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('forMonth')->once()->passthru());

        $this->get(route('budget.show', $this->params()))->assertOk();
    }

    public function test_the_category_detail_is_only_loaded_on_request()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $this->assign($groceries, '2026-08', 60_000);
        $this->spend($groceries, 50_000, '2026-08-10');
        $this->assign($groceries, '2026-09', 70_000);
        $this->spend($groceries, 80_000, '2026-09-10');
        $this->assign($groceries, '2026-10', 65_000);

        $this->get(route('budget.show', $this->params(['month' => '2026-10', 'category' => $groceries->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('detail')
                ->reloadOnly('detail', fn (Assert $reload) => $reload
                    ->where('detail', [
                        'categoryId' => $groceries->id,
                        'carryover' => 0,
                        'assigned' => 65_000,
                        'activity' => 0,
                        'available' => 65_000,
                        'assignedLastMonth' => 70_000,
                        'spentLastMonth' => 80_000,
                        'averageAssigned' => 65_000,
                        'averageSpent' => 65_000,
                    ])));
    }

    public function test_the_budget_includes_pending_invitations_for_the_member()
    {
        $owner = User::factory()->create(['name' => 'Camille']);
        $household = Team::factory()->create(['name' => 'Famille']);
        $household->members()->attach($owner, ['role' => TeamRole::Owner->value]);
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $household->id,
            'email' => $this->user->email,
            'invited_by' => $owner->id,
        ]);
        TeamInvitation::factory()->accepted()->create(['team_id' => $household->id, 'email' => $this->user->email, 'invited_by' => $owner->id]);
        TeamInvitation::factory()->expired()->create(['team_id' => $household->id, 'email' => $this->user->email, 'invited_by' => $owner->id]);
        TeamInvitation::factory()->create(['team_id' => $household->id, 'email' => 'someone@example.com', 'invited_by' => $owner->id]);

        $this->get(route('budget.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page
                ->has('pendingInvitations', 1)
                ->where('pendingInvitations.0.code', $invitation->code)
                ->where('pendingInvitations.0.inviterName', 'Camille')
                ->where('pendingInvitations.0.team.name', 'Famille')
                ->where('pendingInvitations.0.team.slug', $household->slug));
    }
}
