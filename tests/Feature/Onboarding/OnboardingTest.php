<?php

namespace Tests\Feature\Onboarding;

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->team = Team::factory()->notOnboarded()->create();
        $this->team->members()->attach($this->owner, ['role' => 'owner']);
        $this->owner->switchTeam($this->team);
    }

    public function test_a_new_household_opens_on_the_onboarding(): void
    {
        $this->actingAs($this->owner);
        $onboarding = route('onboarding.show', ['current_team' => $this->team]);

        $this->followingRedirects()
            ->get(route('dashboard', ['current_team' => $this->team]))
            ->assertInertia(fn (Assert $page) => $page->component('onboarding/show'));
        $this->get(route('budget.show', ['current_team' => $this->team]))->assertRedirect($onboarding);
        $this->get(route('budget.show', ['current_team' => $this->team, 'month' => '2026-10']))->assertRedirect($onboarding);
    }

    public function test_members_without_owner_rights_are_onboarded_too(): void
    {
        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => 'member']);
        $member->switchTeam($this->team);

        $this->actingAs($member)
            ->get(route('budget.show', ['current_team' => $this->team]))
            ->assertRedirect(route('onboarding.show', ['current_team' => $this->team]));
    }

    public function test_viewers_keep_their_simple_screens(): void
    {
        $viewer = User::factory()->create();
        $this->team->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->switchTeam($this->team);
        $this->actingAs($viewer);

        $this->get(route('budget.show', ['current_team' => $this->team]))
            ->assertRedirect(route('simple.overview', ['current_team' => $this->team]));
        $this->get(route('onboarding.show', ['current_team' => $this->team]))
            ->assertRedirect(route('simple.overview', ['current_team' => $this->team]));
        $this->post(route('onboarding.complete', ['current_team' => $this->team]))->assertForbidden();

        $this->assertNull($this->team->fresh()->onboarded_at);
    }

    public function test_an_onboarded_household_goes_straight_to_its_budget(): void
    {
        $this->team->forceFill(['onboarded_at' => now()])->save();
        $this->actingAs($this->owner);

        $this->get(route('budget.show', ['current_team' => $this->team]))->assertOk();
        $this->get(route('onboarding.show', ['current_team' => $this->team]))
            ->assertRedirect(route('budget.show', ['current_team' => $this->team]));
    }

    public function test_the_onboarding_offers_starter_categories_in_the_members_language(): void
    {
        $this->owner->forceFill(['locale' => 'fr'])->save();

        $this->actingAs($this->owner)
            ->get(route('onboarding.show', ['current_team' => $this->team, 'step' => 'accounts']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/show')
                ->where('step', 'accounts')
                ->where('starterGroups.0.name', __('Bills', [], 'fr'))
                ->where('starterGroups.0.categories.0', '🏠 '.__('Rent or mortgage', [], 'fr'))
                ->has('starterGroups', 4)
                ->where('existingGroups', [])
                ->has('bankingConfigured'));
    }

    public function test_an_unknown_step_starts_at_the_welcome(): void
    {
        $this->actingAs($this->owner)
            ->get(route('onboarding.show', ['current_team' => $this->team, 'step' => 'nope']))
            ->assertInertia(fn (Assert $page) => $page->where('step', 'welcome'));
    }

    public function test_the_member_creates_the_categories_they_kept(): void
    {
        $other = Team::factory()->create();

        $this->actingAs($this->owner)
            ->post(route('onboarding.categories', ['current_team' => $this->team]), [
                'groups' => [
                    ['name' => 'Bills', 'categories' => ['🏠 Rent', '⚡ Electricity']],
                    ['name' => 'Fun', 'categories' => ['🎲 Board games']],
                ],
            ])
            ->assertRedirect(route('onboarding.show', ['current_team' => $this->team, 'step' => 'accounts']));

        $groups = CategoryGroup::withoutGlobalScopes()->with(['categories' => fn ($query) => $query->withoutGlobalScopes()])->orderBy('sort')->get();

        $this->assertSame(['Bills', 'Fun'], $groups->pluck('name')->all());
        $this->assertSame(['🏠 Rent', '⚡ Electricity'], $groups[0]->categories->pluck('name')->all());
        $this->assertSame(['🎲 Board games'], $groups[1]->categories->pluck('name')->all());
        $this->assertSame(3, Category::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
        $this->assertSame(0, Category::withoutGlobalScopes()->where('team_id', $other->id)->count());
        $this->assertNull($this->team->fresh()->onboarded_at);
    }

    public function test_the_categories_are_validated(): void
    {
        $this->actingAs($this->owner);
        $url = route('onboarding.categories', ['current_team' => $this->team]);

        $this->post($url, ['groups' => []])->assertSessionHasErrors('groups');
        $this->post($url, ['groups' => [['name' => '', 'categories' => ['Rent']]]])->assertSessionHasErrors('groups.0.name');
        $this->post($url, ['groups' => [['name' => 'Bills', 'categories' => []]]])->assertSessionHasErrors('groups.0.categories');
        $this->post($url, ['groups' => [['name' => 'Bills', 'categories' => ['Rent', str_repeat('a', 256)]]]])->assertSessionHasErrors('groups.0.categories.1');

        $this->assertSame(0, Category::withoutGlobalScopes()->count());
    }

    public function test_starter_categories_are_not_added_twice(): void
    {
        $group = CategoryGroup::factory()->for($this->team)->create();
        Category::factory()->for($this->team)->create(['category_group_id' => $group->id]);

        $this->actingAs($this->owner)
            ->post(route('onboarding.categories', ['current_team' => $this->team]), [
                'groups' => [['name' => 'Bills', 'categories' => ['Rent']]],
            ])
            ->assertSessionHasErrors('groups');

        $this->assertSame(1, Category::withoutGlobalScopes()->count());
    }

    public function test_finishing_or_skipping_the_setup_marks_the_household_as_onboarded(): void
    {
        $this->actingAs($this->owner)
            ->post(route('onboarding.complete', ['current_team' => $this->team]))
            ->assertRedirect(route('budget.show', ['current_team' => $this->team]));

        $this->assertNotNull($this->team->fresh()->onboarded_at);
        $this->get(route('budget.show', ['current_team' => $this->team]))->assertOk();
    }

    public function test_the_last_step_can_lead_to_the_household_settings(): void
    {
        $this->actingAs($this->owner)
            ->post(route('onboarding.complete', ['current_team' => $this->team]), ['to' => 'settings'])
            ->assertRedirect(route('teams.edit', ['team' => $this->team]));

        $this->assertNotNull($this->team->fresh()->onboarded_at);
    }

    public function test_accounts_added_during_the_onboarding_keep_the_member_on_the_page(): void
    {
        $onboarding = route('onboarding.show', ['current_team' => $this->team, 'step' => 'accounts']);

        $this->actingAs($this->owner)
            ->from($onboarding)
            ->post(route('accounts.store', ['current_team' => $this->team]), [
                'name' => 'Current account',
                'type' => 'checking',
                'on_budget' => true,
                'balance' => 1_000_00,
                'starting_date' => '2026-10-01',
                'stay' => true,
            ])
            ->assertRedirect($onboarding);

        $this->assertSame(1, Account::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_the_migration_marks_households_already_in_use_as_onboarded(): void
    {
        $withCategories = Team::factory()->notOnboarded()->create();
        Category::factory()->for($withCategories)->create([
            'category_group_id' => CategoryGroup::factory()->for($withCategories)->create()->id,
        ]);
        $withAccounts = Team::factory()->notOnboarded()->create();
        Account::factory()->for($withAccounts)->create();

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_11_01_120000_add_onboarded_at_to_teams.php');
        $migration->down();
        $migration->up();

        $this->assertNotNull($withCategories->fresh()->onboarded_at);
        $this->assertNotNull($withAccounts->fresh()->onboarded_at);
        $this->assertNull($this->team->fresh()->onboarded_at);
    }
}
