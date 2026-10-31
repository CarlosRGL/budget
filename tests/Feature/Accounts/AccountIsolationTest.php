<?php

namespace Tests\Feature\Accounts;

use App\Enums\TeamRole;
use App\Models\Account;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_household_account_is_not_found()
    {
        $alex = User::factory()->create();
        $stranger = User::factory()->create();
        $theirs = Account::factory()->for($stranger->currentTeam)->create(['name' => 'Autre banque']);
        $parameters = ['current_team' => $alex->currentTeam, 'account' => $theirs];

        $this->actingAs($alex);

        $this->get(route('accounts.show', $parameters))->assertNotFound();
        $this->patch(route('accounts.update', $parameters), ['name' => 'Piraté', 'on_budget' => true])->assertNotFound();
        $this->post(route('accounts.close', $parameters))->assertNotFound();
        $this->post(route('accounts.reopen', $parameters))->assertNotFound();
        $this->post(route('accounts.balance', $parameters), ['balance' => 0])->assertNotFound();

        $theirs->refresh();
        $this->assertSame('Autre banque', $theirs->name);
        $this->assertNull($theirs->closed_at);
    }

    public function test_a_household_url_cannot_reach_a_household_the_member_does_not_belong_to()
    {
        $alex = User::factory()->create();
        $stranger = User::factory()->create();
        $theirs = Account::factory()->for($stranger->currentTeam)->create();

        $this->actingAs($alex)
            ->get(route('accounts.show', ['current_team' => $stranger->currentTeam, 'account' => $theirs]))
            ->assertForbidden();
    }

    public function test_an_account_is_found_through_the_url_household_of_a_member_of_several()
    {
        $alex = User::factory()->create();
        $familia = Team::factory()->create();
        $familia->members()->attach($alex, ['role' => TeamRole::Owner->value]);
        $shared = Account::factory()->for($familia)->create(['name' => 'Commun']);

        $this->assertNotSame($familia->id, $alex->current_team_id);

        $this->actingAs($alex)
            ->get(route('accounts.show', ['current_team' => $familia, 'account' => $shared]))
            ->assertOk();

        $this->assertSame($familia->id, $alex->refresh()->current_team_id);
    }
}
