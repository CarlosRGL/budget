<?php

namespace Tests\Feature\Banking;

use App\Banking\BankSynchronizer;
use App\Enums\BankConnectionStatus;
use App\Enums\BankStatus;
use App\Enums\ReviewReason;
use App\Jobs\SyncBankConnection;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Category;
use App\Models\Payee;
use App\Models\PayeeRenameRule;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The sync runs in a queued job: no member is signed in, so nothing here acts as one.
 */
class SyncBankConnectionTest extends TestCase
{
    use FakesEnableBanking, RefreshDatabase;

    private Team $team;

    private Account $checking;

    private BankConnection $connection;

    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureEnableBanking();
        $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00'));

        $user = User::factory()->create();
        $this->team = $user->currentTeam;
        $this->checking = Account::factory()->for($this->team)->create(['starting_date' => '2026-10-01']);
        $this->connection = BankConnection::factory()->for($this->team)->for($user)->create();
        $this->bankAccount = BankAccount::factory()->for($this->connection, 'connection')->create([
            'account_id' => $this->checking->id,
            'account_uid' => '07cc67f4-45d6-494b-adac-09b5cbc7e2b5',
        ]);
    }

    public function test_a_new_bank_transaction_is_imported_unapproved_with_a_clean_payee_and_its_card(): void
    {
        $this->import([$this->bank(['entry_reference' => 'E1', 'remittance_information' => ['CARTE 22/10/26 BONNEAU BOULANG CB*2594']])]);

        $transaction = Transaction::sole();
        $this->assertSame(-1809, $transaction->amount);
        $this->assertSame('2026-10-22', $transaction->date->toDateString());
        $this->assertSame('BONNEAU BOULANG', $transaction->payee?->name);
        $this->assertSame('2594', $transaction->card_suffix);
        $this->assertSame('CARTE 22/10/26 BONNEAU BOULANG CB*2594', $transaction->raw_label);
        $this->assertSame('E1', $transaction->external_id);
        $this->assertSame(BankStatus::Booked, $transaction->bank_status);
        $this->assertSame($this->bankAccount->id, $transaction->bank_account_id);
        $this->assertTrue($transaction->cleared);
        $this->assertFalse($transaction->isApproved());
        $this->assertNull($transaction->review_reason);
    }

    public function test_a_known_payee_pre_fills_its_usual_category_but_stays_to_approve(): void
    {
        $courses = Category::factory()->for($this->team)->create();
        Payee::factory()->for($this->team)->create(['name' => 'BONNEAU BOULANG', 'last_category_id' => $courses->id]);

        $this->import([$this->bank()]);

        $transaction = Transaction::sole();
        $this->assertSame($courses->id, $transaction->category_id);
        $this->assertFalse($transaction->isApproved());
        $this->assertSame(1, Payee::count());
    }

    public function test_an_off_budget_account_gets_no_category(): void
    {
        $this->checking->update(['on_budget' => false]);
        $courses = Category::factory()->for($this->team)->create();
        Payee::factory()->for($this->team)->create(['name' => 'BONNEAU BOULANG', 'last_category_id' => $courses->id]);

        $this->import([$this->bank()]);

        $this->assertNull(Transaction::sole()->category_id);
    }

    public function test_a_rename_rule_gives_its_payee(): void
    {
        $boulangerie = Payee::factory()->for($this->team)->create(['name' => 'Boulangerie Bonneau']);
        PayeeRenameRule::create(['team_id' => $this->team->id, 'contains' => 'bonneau', 'payee_id' => $boulangerie->id]);

        $this->import([$this->bank()]);

        $this->assertSame($boulangerie->id, Transaction::sole()->payee_id);
    }

    public function test_only_transactions_after_the_starting_date_are_imported(): void
    {
        $this->import([
            $this->bank(['entry_reference' => 'BEFORE', 'transaction_date' => '2026-09-30']),
            $this->bank(['entry_reference' => 'ON', 'transaction_date' => '2026-10-01']),
            $this->bank(['entry_reference' => 'AFTER', 'transaction_date' => '2026-10-02']),
        ]);

        $this->assertSame(['AFTER'], Transaction::pluck('external_id')->all());
    }

    public function test_cancelled_transactions_are_not_imported(): void
    {
        $this->import([$this->bank(['status' => 'CNCL'])]);

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_resync_matches_on_the_bank_id_and_creates_nothing(): void
    {
        $this->import([$this->bank(['entry_reference' => 'E1'])]);
        Transaction::sole()->update(['memo' => 'Pain et croissants']);

        $this->import([$this->bank(['entry_reference' => 'E1', 'remittance_information' => ['CARTE 22/10/26 BONNEAU BOULANGERIE CB*2594']])]);

        $transaction = Transaction::sole();
        $this->assertSame('Pain et croissants', $transaction->memo);
        $this->assertSame('CARTE 22/10/26 BONNEAU BOULANGERIE CB*2594', $transaction->raw_label);
    }

    public function test_without_a_bank_id_a_resync_matches_on_the_fingerprint(): void
    {
        $this->import([$this->bank(['entry_reference' => null])]);
        $this->import([$this->bank(['entry_reference' => null])]);

        $this->assertSame(1, Transaction::count());
        $this->assertNotNull(Transaction::sole()->fingerprint);
    }

    public function test_two_identical_purchases_without_bank_id_stay_two_after_resyncs(): void
    {
        $coffee = $this->bank(['entry_reference' => null, 'transaction_amount' => ['amount' => '2.20', 'currency' => 'EUR']]);

        $this->import([$coffee, $coffee]);
        $this->import([$coffee, $coffee]);
        $this->import([$coffee, $coffee]);

        $this->assertSame(2, Transaction::count());
    }

    public function test_a_bank_id_change_is_matched_by_the_fingerprint(): void
    {
        $this->import([$this->bank(['entry_reference' => 'OLD'])]);
        $this->import([$this->bank(['entry_reference' => 'NEW'])]);

        $this->assertSame(['NEW'], Transaction::pluck('external_id')->all());
    }

    public function test_a_pending_transaction_booked_under_a_new_id_is_updated_not_duplicated(): void
    {
        $this->import([$this->bank(['entry_reference' => null, 'transaction_id' => 'PDNG-1', 'status' => 'PDNG', 'transaction_date' => '2026-10-28', 'booking_date' => null, 'value_date' => null, 'remittance_information' => ['TOTALENERGIES RELAIS']])]);
        $this->assertFalse(Transaction::sole()->cleared);

        $this->import([$this->bank(['entry_reference' => 'BOOK-1', 'status' => 'BOOK', 'transaction_date' => null, 'booking_date' => '2026-10-30', 'value_date' => '2026-10-29', 'remittance_information' => ['CARTE 28/10/26 TOTALENERGIES CB*7812']])]);

        $transaction = Transaction::sole();
        $this->assertSame('BOOK-1', $transaction->external_id);
        $this->assertSame(BankStatus::Booked, $transaction->bank_status);
        $this->assertTrue($transaction->cleared);
        $this->assertSame('7812', $transaction->card_suffix);
    }

    public function test_a_pending_transaction_is_matched_within_five_days_only(): void
    {
        $this->import([$this->bank(['entry_reference' => 'PDNG-1', 'status' => 'PDNG', 'transaction_date' => '2026-10-20'])]);
        $this->import([$this->bank(['entry_reference' => 'BOOK-9', 'transaction_date' => '2026-10-26', 'value_date' => '2026-10-26', 'remittance_information' => ['AUTRE ACHAT']])]);

        $this->assertSame(2, Transaction::count());

        $this->import([$this->bank(['entry_reference' => 'BOOK-8', 'transaction_date' => '2026-10-25', 'value_date' => '2026-10-25', 'remittance_information' => ['ENCORE UN ACHAT']])]);

        $this->assertSame(2, Transaction::count(), 'Within five days, the dropped pending transaction is the one booked.');
    }

    public function test_an_approved_transaction_whose_amount_changed_goes_back_to_the_inbox(): void
    {
        $this->import([$this->bank(['entry_reference' => 'FUEL', 'status' => 'PDNG', 'transaction_amount' => ['amount' => '120.00', 'currency' => 'EUR']])]);
        Transaction::sole()->update(['approved_at' => now(), 'approved_by' => $this->connection->user_id]);

        $this->import([$this->bank(['entry_reference' => 'FUEL', 'status' => 'BOOK', 'transaction_amount' => ['amount' => '45.12', 'currency' => 'EUR']])]);

        $transaction = Transaction::sole();
        $this->assertSame(-4512, $transaction->amount);
        $this->assertFalse($transaction->isApproved());
        $this->assertSame(ReviewReason::AmountChanged, $transaction->review_reason);
        $this->assertSame(1, Transaction::query()->inInbox()->count());
    }

    public function test_an_unapproved_transaction_whose_amount_changed_just_takes_the_new_amount(): void
    {
        $this->import([$this->bank(['entry_reference' => 'FUEL', 'status' => 'PDNG', 'transaction_amount' => ['amount' => '120.00', 'currency' => 'EUR']])]);
        $this->import([$this->bank(['entry_reference' => 'FUEL', 'status' => 'BOOK', 'transaction_amount' => ['amount' => '45.12', 'currency' => 'EUR']])]);

        $transaction = Transaction::sole();
        $this->assertSame(-4512, $transaction->amount);
        $this->assertNull($transaction->review_reason);
    }

    public function test_a_pending_transaction_the_bank_drops_is_flagged_a_week_later_and_never_deleted(): void
    {
        $this->import([$this->bank(['entry_reference' => 'HOTEL', 'status' => 'PDNG', 'transaction_date' => '2026-10-27'])]);
        Transaction::sole()->update(['approved_at' => now()]);

        // Card authorizations often leave the list a few hours before being booked.
        $this->import([]);
        $this->assertNull(Transaction::sole()->review_reason);
        $this->assertTrue(Transaction::sole()->isApproved());

        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
        $this->import([]);

        $transaction = Transaction::sole();
        $this->assertSame(ReviewReason::RemovedByBank, $transaction->review_reason);
        $this->assertFalse($transaction->isApproved());
    }

    public function test_a_booked_transaction_missing_from_the_list_is_left_alone(): void
    {
        $this->import([$this->bank(['entry_reference' => 'E1'])]);

        $this->import([]);

        $this->assertNull(Transaction::sole()->review_reason);
    }

    public function test_a_dropped_pending_transaction_that_comes_back_loses_its_flag(): void
    {
        $pending = $this->bank(['entry_reference' => 'HOTEL', 'status' => 'PDNG', 'transaction_date' => '2026-10-27']);
        $this->import([$pending]);
        $this->import([]);

        $this->import([$pending]);

        $this->assertNull(Transaction::sole()->review_reason);
    }

    public function test_a_transaction_typed_by_hand_is_offered_for_reconciliation_instead_of_duplicated_silently(): void
    {
        Transaction::factory()->for($this->team)->for($this->checking)->approved()->create(['date' => '2026-10-20', 'amount' => -1809]);

        $this->import([$this->bank(['transaction_date' => '2026-10-22'])]);

        $imported = Transaction::whereNotNull('fingerprint')->sole();
        $this->assertSame(ReviewReason::PossibleDuplicate, $imported->review_reason);
        $this->assertFalse($imported->isApproved());
    }

    public function test_a_typed_transaction_too_far_in_time_is_not_a_duplicate(): void
    {
        Transaction::factory()->for($this->team)->for($this->checking)->create(['date' => '2026-10-10', 'amount' => -1809]);

        $this->import([$this->bank(['transaction_date' => '2026-10-22'])]);

        $this->assertNull(Transaction::whereNotNull('fingerprint')->sole()->review_reason);
    }

    public function test_the_job_imports_every_page_stores_the_bank_balance_and_three_syncs_create_no_duplicate(): void
    {
        $this->fakeBank();

        SyncBankConnection::dispatchSync($this->connection);
        SyncBankConnection::dispatchSync($this->connection);
        SyncBankConnection::dispatchSync($this->connection);

        $this->assertSame(3, Transaction::count());
        $this->assertEqualsCanonicalizing(['BONNEAU BOULANG', 'EMPLOYEUR SAS SALAIRE OCTOBRE', 'TOTALENERGIES RELAIS'], Transaction::with('payee')->get()->pluck('payee.name')->all());

        $this->bankAccount->refresh();
        $this->assertSame(1461_05, $this->bankAccount->last_balance);
        $this->assertNotNull($this->bankAccount->last_balance_at);
        $this->assertNotNull($this->connection->refresh()->last_synced_at);
        $this->assertNull($this->connection->last_error);
    }

    public function test_the_first_sync_starts_after_the_starting_date_then_ten_days_before_the_previous_one(): void
    {
        $synchronizer = app(BankSynchronizer::class);

        $this->assertSame('2026-10-02', $synchronizer->dateFrom($this->checking, $this->bankAccount)->toDateString());

        $this->bankAccount->last_balance_at = now();
        $this->assertSame('2026-10-20', $synchronizer->dateFrom($this->checking, $this->bankAccount)->toDateString());

        $this->bankAccount->last_balance_at = now()->subMonths(3);
        $this->assertSame('2026-10-02', $synchronizer->dateFrom($this->checking, $this->bankAccount)->toDateString());
    }

    public function test_an_account_starting_today_never_asks_for_a_future_date(): void
    {
        $this->checking->update(['starting_date' => '2026-10-30']);

        $this->assertSame('2026-10-30', app(BankSynchronizer::class)->dateFrom($this->checking, $this->bankAccount)->toDateString());
    }

    public function test_a_new_account_starting_balance_follows_the_bank_balance(): void
    {
        $this->checking->update(['starting_date' => '2026-10-28']);
        $start = Transaction::factory()->for($this->team)->for($this->checking)->create([
            'date' => '2026-10-28', 'amount' => 2000_00, 'is_starting_balance' => true,
        ]);
        $this->fakeBank();

        SyncBankConnection::dispatchSync($this->connection);

        $this->assertSame(1461_05, (int) Transaction::where('account_id', $this->checking->id)->sum('amount'));
        $this->assertNotSame(2000_00, $start->refresh()->amount);
    }

    public function test_an_older_account_keeps_its_starting_balance(): void
    {
        $start = Transaction::factory()->for($this->team)->for($this->checking)->create([
            'date' => '2026-10-01', 'amount' => 2000_00, 'is_starting_balance' => true,
        ]);
        $this->fakeBank();

        SyncBankConnection::dispatchSync($this->connection);

        $this->assertSame(2000_00, $start->refresh()->amount);
    }

    public function test_unmapped_bank_accounts_are_not_read(): void
    {
        $this->bankAccount->update(['account_id' => null]);
        Http::fake();

        SyncBankConnection::dispatchSync($this->connection);

        Http::assertNothingSent();
        $this->assertNotNull($this->connection->refresh()->last_synced_at);
    }

    public function test_an_expired_consent_marks_the_connection_expired(): void
    {
        Http::fake(['api.enablebanking.com/*' => Http::response($this->fixture('error-expired-session'), 401)]);

        SyncBankConnection::dispatchSync($this->connection);

        $this->connection->refresh();
        $this->assertSame(BankConnectionStatus::Expired, $this->connection->status);
        $this->assertSame('Session is expired', $this->connection->last_error);
        $this->assertSame(0, Transaction::count());
    }

    public function test_the_scheduled_command_queues_only_valid_connections(): void
    {
        BankConnection::factory()->for($this->team)->create(['status' => BankConnectionStatus::Expired]);
        BankConnection::factory()->for($this->team)->create(['valid_until' => now()->subDay()]);
        $this->fakeBank();

        $this->artisan('bank:sync')->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), $this->bankAccount->account_uid));
        $this->assertSame(3, Transaction::count());
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     */
    private function import(array $transactions): void
    {
        app(BankSynchronizer::class)->import($this->bankAccount, $this->checking->refresh(), $transactions, CarbonImmutable::parse('2026-10-02'));
    }

    /**
     * A booked card payment of 18,09 € on 22/10/2026, as EnableBanking returns it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bank(array $overrides = []): array
    {
        return [
            'entry_reference' => 'E1',
            'transaction_id' => null,
            'transaction_amount' => ['currency' => 'EUR', 'amount' => '18.09'],
            'credit_debit_indicator' => 'DBIT',
            'status' => 'BOOK',
            'booking_date' => '2026-10-23',
            'value_date' => '2026-10-22',
            'transaction_date' => '2026-10-22',
            'remittance_information' => ['CARTE 22/10/26 BONNEAU BOULANG CB*2594'],
            ...$overrides,
        ];
    }

    private function fakeBank(): void
    {
        Http::fake([
            'api.enablebanking.com/accounts/*/transactions*' => fn (Request $request) => Http::response(
                $this->fixture(isset($request['continuation_key']) ? 'transactions-page-2' : 'transactions-page-1'),
            ),
            'api.enablebanking.com/accounts/*/balances' => Http::response($this->fixture('balances')),
        ]);
    }
}
