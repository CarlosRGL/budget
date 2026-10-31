<?php

namespace Tests\Feature\Domain;

use App\Enums\AccountType;
use App\Enums\MoneyMoveKind;
use App\Enums\TargetType;
use App\Models\Account;
use App\Models\Assignment;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\MoneyMove;
use App\Models\Payee;
use App\Models\Target;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HouseholdModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_complete_household_can_be_built()
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $checking = Account::factory()->for($team)->create(['name' => 'Boursorama', 'type' => AccountType::Checking]);
        $savings = Account::factory()->for($team)->create(['name' => 'Livret A', 'type' => AccountType::Savings]);
        $loan = Account::factory()->for($team)->create(['name' => 'Crédit Maison', 'type' => AccountType::Loan]);

        $this->assertTrue($checking->on_budget);
        $this->assertTrue($savings->on_budget);
        $this->assertFalse($loan->on_budget);

        $group = CategoryGroup::factory()->for($team)->create(['name' => 'Gastos Fijos']);
        $courses = Category::factory()->for($team)->for($group, 'group')->create(['name' => 'Courses']);
        $couches = Category::factory()->for($team)->for($group, 'group')->create(['name' => 'Couches']);

        $leclerc = Payee::factory()->for($team)->create(['name' => 'Leclerc', 'last_category_id' => $courses->id]);

        $purchase = Transaction::factory()->for($team)->for($checking)->create([
            'payee_id' => $leclerc->id,
            'category_id' => null,
            'amount' => -10_000,
        ]);
        $purchase->splits()->createMany([
            ['category_id' => $courses->id, 'amount' => -7_000],
            ['category_id' => $couches->id, 'amount' => -3_000],
        ]);

        [$out, $in] = Transaction::createTransfer($checking, $savings, 50_000, CarbonImmutable::parse('2026-10-15'), $user);

        Assignment::factory()->for($team)->for($courses)->create(['month' => '2026-10-01', 'amount' => 65_000]);
        Target::factory()->for($team)->for($courses)->create(['type' => TargetType::RefillUpTo, 'amount' => 65_000]);
        MoneyMove::factory()->for($team)->create([
            'month' => '2026-10-01',
            'to_category_id' => $courses->id,
            'amount' => 65_000,
            'kind' => MoneyMoveKind::Assign,
            'user_id' => $user->id,
        ]);

        $this->assertSame($group->id, $courses->group->id);
        $this->assertCount(2, $group->categories);
        $this->assertTrue($purchase->isSplit());
        $this->assertTrue($purchase->fresh()->splitsAreBalanced());
        $this->assertSame($courses->id, $leclerc->lastCategory->id);
        $this->assertSame(-50_000, $out->amount);
        $this->assertSame(50_000, $in->amount);
        $this->assertTrue($out->transferPair->is($in));
        $this->assertTrue($in->transferPair->is($out));
        $this->assertSame($savings->id, $out->payee->transfer_account_id);
        $this->assertSame(65_000, $courses->target->amount);
        $this->assertSame('2026-10-01', $courses->assignments->first()->month->toDateString());
        $this->assertCount(3, $team->accounts);
    }

    public function test_unbalanced_splits_are_detected()
    {
        $transaction = Transaction::factory()->create(['amount' => -10_000, 'category_id' => null]);
        $transaction->splits()->create(['category_id' => Category::factory()->create()->id, 'amount' => -9_000]);

        $this->assertFalse($transaction->fresh()->splitsAreBalanced());
    }

    public function test_deleting_a_team_removes_its_budget_data()
    {
        $team = Team::factory()->create();
        Account::factory()->for($team)->create();

        $team->forceDelete();

        $this->assertSame(0, Account::withoutGlobalScopes()->count());
    }
}
