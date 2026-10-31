<?php

namespace Tests\Feature\Domain;

use App\Enums\TeamRole;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_only_see_their_own_household_data()
    {
        $alex = User::factory()->create();
        $stranger = User::factory()->create();

        Account::factory()->for($alex->currentTeam)->create(['name' => 'Boursorama']);
        Account::factory()->for($stranger->currentTeam)->create(['name' => 'Autre banque']);
        Transaction::factory()->for($stranger->currentTeam)->create();
        CategoryGroup::factory()->for($stranger->currentTeam)->create();

        $this->actingAs($alex);

        $this->assertSame(['Boursorama'], Account::pluck('name')->all());
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, CategoryGroup::count());
        $this->assertNull(Account::where('name', 'Autre banque')->first());
    }

    public function test_new_records_belong_to_the_current_household()
    {
        $alex = User::factory()->create();
        $this->actingAs($alex);

        $group = CategoryGroup::create(['name' => 'Gastos Fijos']);
        $category = Category::create(['name' => 'Courses', 'category_group_id' => $group->id]);

        $this->assertSame($alex->current_team_id, $group->team_id);
        $this->assertSame($alex->current_team_id, $category->team_id);
    }

    public function test_switching_household_changes_what_is_visible()
    {
        $alex = User::factory()->create();
        $personal = $alex->currentTeam;
        $familia = Team::factory()->create();
        $familia->members()->attach($alex, ['role' => TeamRole::Owner->value]);

        Account::factory()->for($personal)->create(['name' => 'Perso']);
        Account::factory()->for($familia)->create(['name' => 'Commun']);

        $this->actingAs($alex);
        $this->assertSame(['Perso'], Account::pluck('name')->all());

        $alex->switchTeam($familia);
        $this->assertSame(['Commun'], Account::pluck('name')->all());
    }
}
