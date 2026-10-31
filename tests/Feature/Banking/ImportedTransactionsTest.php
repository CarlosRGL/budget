<?php

namespace Tests\Feature\Banking;

use App\Enums\BankStatus;
use App\Enums\ReviewReason;
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

/**
 * What members do with imported transactions: reconcile, dismiss hints, rename.
 */
class ImportedTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Account $checking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
        $this->checking = Account::factory()->for($this->team)->create();
        $this->actingAs($this->user);
    }

    public function test_the_register_shows_the_card_and_the_review_hint(): void
    {
        $this->imported(['card_suffix' => '2594', 'review_reason' => ReviewReason::AmountChanged, 'bank_status' => BankStatus::Pending]);

        $this->get(route('accounts.show', ['current_team' => $this->team, 'account' => $this->checking]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('register.transactions.0.cardSuffix', '2594')
                ->where('register.transactions.0.reviewReason', 'amount_changed')
                ->where('register.transactions.0.bankPending', true)
                ->where('register.transactions.0.imported', true));
    }

    public function test_reconciling_merges_the_bank_transaction_into_the_typed_one(): void
    {
        $courses = Category::factory()->for($this->team)->create();
        $typed = Transaction::factory()->for($this->team)->for($this->checking)->approved()->create([
            'date' => '2026-10-20', 'amount' => -1809, 'category_id' => $courses->id, 'memo' => 'Pain',
        ]);
        $imported = $this->imported(['date' => '2026-10-22', 'amount' => -1809, 'review_reason' => ReviewReason::PossibleDuplicate, 'cleared' => true]);

        $this->post(route('transactions.merge', ['current_team' => $this->team, 'transaction' => $imported]))
            ->assertRedirect()
            ->assertInertiaFlash('toast.message', 'Transactions matched.');

        $this->assertModelMissing($imported);
        $typed->refresh();
        $this->assertSame('E1', $typed->external_id);
        $this->assertSame('fp-1', $typed->fingerprint);
        $this->assertSame('2594', $typed->card_suffix);
        $this->assertTrue($typed->cleared);
        $this->assertTrue($typed->isApproved());
        $this->assertSame($courses->id, $typed->category_id);
        $this->assertSame('Pain', $typed->memo);
    }

    public function test_only_a_possible_duplicate_can_be_reconciled(): void
    {
        $imported = $this->imported();

        $this->post(route('transactions.merge', ['current_team' => $this->team, 'transaction' => $imported]))->assertStatus(422);
    }

    public function test_keeping_both_transactions_drops_the_hint(): void
    {
        $imported = $this->imported(['review_reason' => ReviewReason::PossibleDuplicate]);

        $this->post(route('transactions.dismiss-review', ['current_team' => $this->team, 'transaction' => $imported]))->assertRedirect();

        $this->assertNull($imported->refresh()->review_reason);
    }

    public function test_approving_answers_the_hint(): void
    {
        $imported = $this->imported(['review_reason' => ReviewReason::AmountChanged]);

        $this->post(route('transactions.approve', $this->team), ['ids' => [$imported->id]])->assertRedirect();

        $imported->refresh();
        $this->assertTrue($imported->isApproved());
        $this->assertNull($imported->review_reason);
    }

    public function test_renaming_an_imported_payee_can_create_a_rename_rule(): void
    {
        $imported = $this->imported();

        $this->patch(route('transactions.update', ['current_team' => $this->team, 'transaction' => $imported]), [
            'account_id' => $this->checking->id,
            'date' => '2026-10-22',
            'payee_name' => 'Boulangerie Bonneau',
            'amount' => -1809,
            'rename_rule_contains' => 'BONNEAU BOULANG',
        ])->assertSessionHasNoErrors();

        $rule = PayeeRenameRule::sole();
        $this->assertSame('BONNEAU BOULANG', $rule->contains);
        $this->assertSame('Boulangerie Bonneau', $rule->payee->name);
        $this->assertSame($rule->payee_id, $imported->refresh()->payee_id);
    }

    public function test_no_rule_is_created_for_a_typed_transaction(): void
    {
        $typed = Transaction::factory()->for($this->team)->for($this->checking)->create();

        $this->patch(route('transactions.update', ['current_team' => $this->team, 'transaction' => $typed]), [
            'account_id' => $this->checking->id,
            'date' => '2026-10-22',
            'payee_name' => 'Boulangerie Bonneau',
            'amount' => -1809,
            'rename_rule_contains' => 'BONNEAU',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, PayeeRenameRule::count());
    }

    public function test_the_rules_page_lists_the_household_rules(): void
    {
        $this->rule('BONNEAU', 'Boulangerie Bonneau');
        $stranger = User::factory()->create();
        PayeeRenameRule::create(['team_id' => $stranger->current_team_id, 'contains' => 'X', 'payee_id' => Payee::factory()->for($stranger->currentTeam)->create()->id]);

        $this->get(route('rename-rules.index', $this->team))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('rename-rules/index')
                ->has('rules', 1)
                ->where('rules.0.contains', 'BONNEAU')
                ->where('rules.0.payee.name', 'Boulangerie Bonneau'));
    }

    public function test_a_rule_can_be_edited_and_deleted(): void
    {
        $rule = $this->rule('BONNEAU', 'Boulangerie Bonneau');

        $this->patch(route('rename-rules.update', ['current_team' => $this->team, 'payee_rename_rule' => $rule]), [
            'contains' => 'BONNEAU BOUL',
            'payee_name' => 'Bonneau',
        ])->assertSessionHasNoErrors();

        $rule->refresh();
        $this->assertSame('BONNEAU BOUL', $rule->contains);
        $this->assertSame('Bonneau', $rule->payee->name);

        $this->delete(route('rename-rules.destroy', ['current_team' => $this->team, 'payee_rename_rule' => $rule]))->assertRedirect();
        $this->assertModelMissing($rule);
    }

    public function test_a_rule_can_be_applied_to_existing_imported_transactions(): void
    {
        $rule = $this->rule('bonneau', 'Boulangerie Bonneau');
        $matching = $this->imported(['raw_label' => 'CARTE 22/10/26 BONNEAU BOULANG CB*2594']);
        $other = $this->imported(['raw_label' => 'PRLV SEPA EDF', 'fingerprint' => 'fp-2', 'external_id' => 'E2']);
        $typed = Transaction::factory()->for($this->team)->for($this->checking)->create(['memo' => 'BONNEAU']);

        $this->post(route('rename-rules.apply', ['current_team' => $this->team, 'payee_rename_rule' => $rule]))
            ->assertInertiaFlash('toast.message', '1 transaction renamed.');

        $this->assertSame($rule->payee_id, $matching->refresh()->payee_id);
        $this->assertNotSame($rule->payee_id, $other->refresh()->payee_id);
        $this->assertNull($typed->refresh()->payee_id);
    }

    public function test_another_household_rule_is_not_found(): void
    {
        $stranger = User::factory()->create();
        $rule = PayeeRenameRule::create(['team_id' => $stranger->current_team_id, 'contains' => 'X', 'payee_id' => Payee::factory()->for($stranger->currentTeam)->create()->id]);

        $this->post(route('rename-rules.apply', ['current_team' => $this->team, 'payee_rename_rule' => $rule]))->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function imported(array $attributes = []): Transaction
    {
        return Transaction::factory()->for($this->team)->for($this->checking)->create([
            'date' => '2026-10-22',
            'amount' => -1809,
            'payee_id' => Payee::firstOrCreate(['team_id' => $this->team->id, 'name' => 'BONNEAU BOULANG'])->id,
            'external_id' => 'E1',
            'fingerprint' => 'fp-1',
            'bank_status' => BankStatus::Booked,
            'raw_label' => 'CARTE 22/10/26 BONNEAU BOULANG CB*2594',
            'card_suffix' => '2594',
            ...$attributes,
        ]);
    }

    private function rule(string $contains, string $payeeName): PayeeRenameRule
    {
        return PayeeRenameRule::create([
            'team_id' => $this->team->id,
            'contains' => $contains,
            'payee_id' => Payee::factory()->for($this->team)->create(['name' => $payeeName])->id,
        ]);
    }
}
