<?php

namespace Tests\Feature\Household;

use App\Enums\TeamRole;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\MoneyMove;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 7: Alex invites Camille, who joins the same household with the same rights.
 */
class HouseholdSharingTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    private Team $familia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alex = User::factory()->create(['name' => 'Alex']);
        $this->familia = Team::factory()->create(['name' => 'Familia']);
        $this->familia->members()->attach($this->alex, ['role' => TeamRole::Owner->value]);
        $this->alex->switchTeam($this->familia);
    }

    public function test_an_invitation_is_sent_right_away_and_lasts_seven_days()
    {
        Notification::fake();
        $this->travelTo(now()->startOfDay());

        $this->actingAs($this->alex)
            ->post(route('teams.invitations.store', $this->familia), ['email' => 'camille@example.com', 'role' => 'admin'])
            ->assertRedirect();

        $invitation = TeamInvitation::firstOrFail();
        $this->assertTrue($invitation->expires_at->equalTo(now()->addDays(7)));
        Notification::assertSentOnDemand(
            TeamInvitationNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'camille@example.com',
        );
        $this->assertNotInstanceOf(ShouldQueue::class, new TeamInvitationNotification($invitation));
    }

    public function test_the_household_page_gives_the_invitation_link_to_share_by_hand()
    {
        $invitation = $this->invite('camille@example.com');

        $this->actingAs($this->alex)
            ->get(route('teams.edit', $this->familia))
            ->assertInertia(fn (Assert $page) => $page
                ->where('invitations.0.url', route('register', ['invitation' => $invitation->code])));
    }

    public function test_the_invited_person_joins_the_household_without_a_personal_one()
    {
        $invitation = $this->invite('Camille@Example.com');

        $this->post(route('register.store'), [
            'name' => 'Camille',
            'email' => 'camille@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $camille = User::where('email', 'camille@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($camille);
        $this->assertTrue($camille->hasVerifiedEmail());
        $this->assertSame([$this->familia->id], $camille->teams()->pluck('teams.id')->all());
        $this->assertSame($this->familia->id, $camille->current_team_id);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertNull($camille->personalTeam());
    }

    public function test_both_members_see_and_change_the_same_budget()
    {
        $camille = $this->joinedMember('Camille');
        $group = CategoryGroup::factory()->for($this->familia)->create();
        $courses = Category::factory()->for($this->familia)->for($group, 'group')->create(['name' => 'Courses']);

        $this->actingAs($camille)
            ->put(route('budget.assignment.update', ['current_team' => $this->familia->slug, 'month' => '2026-10', 'category' => $courses->id]), ['amount' => 65_000])
            ->assertRedirect();

        $this->actingAs($this->alex)
            ->get(route('budget.show', ['current_team' => $this->familia->slug, 'month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groups.0.categories.0.assigned', 65_000)
                ->where('moves.0.user', 'Camille'));

        $this->assertSame($camille->id, MoneyMove::firstOrFail()->user_id);
    }

    public function test_a_removed_member_without_another_household_is_left_without_one()
    {
        $camille = $this->joinedMember('Camille');

        $this->actingAs($this->alex)
            ->delete(route('teams.members.destroy', ['team' => $this->familia, 'user' => $camille]))
            ->assertRedirect();

        $this->assertNull($camille->fresh()->current_team_id);
        $this->actingAs($camille->fresh())
            ->get(route('budget.show', ['current_team' => $this->familia->slug]))
            ->assertForbidden();
    }

    private function invite(string $email): TeamInvitation
    {
        return TeamInvitation::factory()->create([
            'team_id' => $this->familia->id,
            'email' => $email,
            'role' => TeamRole::Admin->value,
            'invited_by' => $this->alex->id,
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function joinedMember(string $name): User
    {
        $member = User::factory()->create(['name' => $name]);
        $member->teams()->detach();
        $this->familia->members()->attach($member, ['role' => TeamRole::Admin->value]);
        $member->switchTeam($this->familia);

        return $member;
    }
}
