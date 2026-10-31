<?php

namespace Tests\Feature\Transactions;

use App\Enums\PayeeCategoryMode;
use App\Models\Account;
use App\Models\Category;
use App\Models\Payee;
use App\Models\PayeeRenameRule;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ManagePayeesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
        $this->actingAs($this->user);
    }

    public function test_the_page_lists_payees_with_their_use_but_not_transfers()
    {
        $leclerc = Payee::factory()->for($this->team)->create(['name' => 'Leclerc']);
        Payee::factory()->for($this->team)->create(['name' => 'Virement : Livret A', 'transfer_account_id' => Account::factory()->for($this->team)]);
        Transaction::factory()->for($this->team)->count(2)->create(['payee_id' => $leclerc->id]);

        $this->get(route('payees.index', $this->team))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('payees/index')
                ->has('payees', 1)
                ->where('payees.0.name', 'Leclerc')
                ->where('payees.0.transactions', 2));
    }

    public function test_a_payee_can_be_renamed_and_given_a_default_category()
    {
        $payee = Payee::factory()->for($this->team)->create(['name' => 'CARREFOUR 123']);
        $courses = Category::factory()->for($this->team)->create();

        $this->patch(route('payees.update', [$this->team, $payee]), ['name' => ' Carrefour ', 'category_id' => $courses->id])
            ->assertSessionHasNoErrors();

        $payee->refresh();
        $this->assertSame('Carrefour', $payee->name);
        $this->assertSame($courses->id, $payee->last_category_id);
    }

    public function test_rename_rules_gather_the_matching_bank_labels_and_categorize_them()
    {
        $courses = Category::factory()->for($this->team)->create();
        $aldi = Payee::factory()->for($this->team)->create(['name' => 'ALDI FRSAU246\\SAINTE MARIE\\ FR']);
        $other = Payee::factory()->for($this->team)->create(['name' => 'ALDI MARCHE 312']);
        $first = Transaction::factory()->for($this->team)->create(['payee_id' => $aldi->id, 'raw_label' => 'ALDI FRSAU246\\SAINTE MARIE\\ FR']);
        $second = Transaction::factory()->for($this->team)->create(['payee_id' => $other->id, 'raw_label' => 'CARTE 12/10 Aldi Marche 312']);
        $unrelated = Transaction::factory()->for($this->team)->create(['raw_label' => 'LIDL 44']);

        $this->patch(route('payees.update', [$this->team, $aldi]), [
            'name' => 'Aldi',
            'category_id' => $courses->id,
            'category_mode' => 'fixed',
            'rules' => ['ALDI'],
            'apply_to_existing' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Aldi', $aldi->refresh()->name);
        $this->assertSame(PayeeCategoryMode::Fixed, $aldi->category_mode);
        $this->assertSame(['ALDI'], $aldi->renameRules()->pluck('contains')->all());
        foreach ([$first, $second] as $transaction) {
            $transaction->refresh();
            $this->assertSame($aldi->id, $transaction->payee_id);
            $this->assertSame($courses->id, $transaction->category_id);
        }
        $this->assertNull($unrelated->refresh()->category_id);
        $this->assertModelMissing($other);
    }

    public function test_rules_left_out_are_removed_and_a_payee_can_stop_suggesting_a_category()
    {
        $courses = Category::factory()->for($this->team)->create();
        $payee = Payee::factory()->for($this->team)->create(['name' => 'Aldi', 'last_category_id' => $courses->id]);
        PayeeRenameRule::create(['team_id' => $this->team->id, 'contains' => 'ALDI', 'payee_id' => $payee->id]);

        $this->patch(route('payees.update', [$this->team, $payee]), [
            'name' => 'Aldi',
            'category_id' => $courses->id,
            'category_mode' => 'none',
            'rules' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, PayeeRenameRule::count());
        $this->assertNull($payee->refresh()->defaultCategoryId());
    }

    public function test_renaming_to_an_existing_name_merges_the_payees()
    {
        $duplicate = Payee::factory()->for($this->team)->create(['name' => 'Leclerc Drive']);
        $leclerc = Payee::factory()->for($this->team)->create(['name' => 'Leclerc']);
        $transaction = Transaction::factory()->for($this->team)->create(['payee_id' => $duplicate->id]);
        $rule = PayeeRenameRule::create(['team_id' => $this->team->id, 'contains' => 'LECLERC DRV', 'payee_id' => $duplicate->id]);

        $this->patch(route('payees.update', [$this->team, $duplicate]), ['name' => 'Leclerc'])->assertSessionHasNoErrors();

        $this->assertModelMissing($duplicate);
        $this->assertSame($leclerc->id, $transaction->refresh()->payee_id);
        $this->assertSame($leclerc->id, $rule->refresh()->payee_id);
    }

    public function test_several_payees_can_be_merged_into_one()
    {
        $courses = Category::factory()->for($this->team)->create();
        $into = Payee::factory()->for($this->team)->create(['name' => 'Amazon']);
        $a = Payee::factory()->for($this->team)->create(['name' => 'AMZN Mktp', 'last_category_id' => $courses->id]);
        $b = Payee::factory()->for($this->team)->create(['name' => 'Amazon.fr']);
        Transaction::factory()->for($this->team)->create(['payee_id' => $a->id]);
        Transaction::factory()->for($this->team)->create(['payee_id' => $b->id]);

        $this->post(route('payees.merge', $this->team), ['into' => $into->id, 'ids' => [$a->id, $b->id, $into->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Payee::count());
        $this->assertSame(2, Transaction::where('payee_id', $into->id)->count());
        $this->assertSame($courses->id, $into->refresh()->last_category_id);
    }

    public function test_deleting_a_payee_keeps_its_transactions()
    {
        $payee = Payee::factory()->for($this->team)->create();
        $transaction = Transaction::factory()->for($this->team)->create(['payee_id' => $payee->id]);

        $this->delete(route('payees.destroy', [$this->team, $payee]))->assertRedirect();

        $this->assertModelMissing($payee);
        $this->assertNull($transaction->refresh()->payee_id);
    }

    public function test_another_households_payees_cannot_be_touched()
    {
        $stranger = User::factory()->create();
        $foreign = Payee::factory()->for($stranger->currentTeam)->create();
        $mine = Payee::factory()->for($this->team)->create();
        $foreignCategory = Category::factory()->for($stranger->currentTeam)->create();

        $this->patch(route('payees.update', [$this->team, $foreign]), ['name' => 'X'])->assertNotFound();
        $this->delete(route('payees.destroy', [$this->team, $foreign]))->assertNotFound();
        $this->post(route('payees.merge', $this->team), ['into' => $mine->id, 'ids' => [$foreign->id]])->assertSessionHasErrors('ids.0');
        $this->patch(route('payees.update', [$this->team, $mine]), ['name' => 'Y', 'category_id' => $foreignCategory->id])->assertSessionHasErrors('category_id');
        $this->assertModelExists($foreign);
    }
}
