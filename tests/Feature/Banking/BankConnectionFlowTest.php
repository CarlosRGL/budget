<?php

namespace Tests\Feature\Banking;

use App\Enums\AccountType;
use App\Enums\BankConnectionStatus;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class BankConnectionFlowTest extends TestCase
{
    use FakesEnableBanking, RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureEnableBanking();
        $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00'));

        $this->user = User::factory()->create(['name' => 'Alex']);
        $this->team = $this->user->currentTeam;
        $this->actingAs($this->user);
    }

    public function test_the_page_lists_the_household_connections_only(): void
    {
        $connection = BankConnection::factory()->for($this->team)->for($this->user)->create();
        BankAccount::factory()->for($connection, 'connection')->create(['last_balance' => 1000_00]);
        BankConnection::factory()->create(['aspsp_name' => 'Banque d’un autre foyer']);

        $this->get(route('bank-connections.index', $this->team))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('bank-connections/index')
                ->where('configured', true)
                ->has('connections', 1)
                ->where('connections.0.aspspName', 'Crédit Agricole')
                ->where('connections.0.isOwner', true)
                ->where('connections.0.accounts.0.bankBalance', 1000_00)
                ->missing('aspsps'));
    }

    public function test_banks_of_a_country_are_loaded_on_demand(): void
    {
        Http::fake(['api.enablebanking.com/aspsps*' => Http::response($this->fixture('aspsps'))]);

        $this->get(route('bank-connections.index', ['current_team' => $this->team, 'country' => 'FR']))
            ->assertInertia(fn (AssertableInertia $page) => $page->reloadOnly('aspsps', fn (AssertableInertia $reload) => $reload
                ->where('aspsps.0.name', 'Boursorama')
                ->where('aspsps.1.maximumConsentDays', 180)));
    }

    public function test_connecting_a_bank_sends_the_member_to_enablebanking(): void
    {
        Http::fake([
            'api.enablebanking.com/aspsps*' => Http::response($this->fixture('aspsps')),
            'api.enablebanking.com/auth' => Http::response($this->fixture('auth')),
        ]);

        $this->post(route('bank-connections.store', $this->team), ['country' => 'FR', 'aspsp_name' => 'Crédit Agricole'])
            ->assertRedirect($this->fixture('auth')['url']);

        $connection = BankConnection::sole();
        $this->assertSame(BankConnectionStatus::Pending, $connection->status);
        $this->assertSame($this->user->id, $connection->user_id);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/auth')
            && $request['state'] === $connection->auth_state
            && $request['redirect_url'] === route('bank.callback')
            && str_starts_with($request['access']['valid_until'], '2027-04-28'));
    }

    public function test_a_failed_authorization_start_leaves_nothing_behind(): void
    {
        Http::fake(['api.enablebanking.com/*' => Http::response(['message' => 'Wrong ASPSP name', 'error' => 'WRONG_ASPSP_PROVIDED', 'code' => 422], 422)]);

        $this->from(route('bank-connections.index', $this->team))
            ->post(route('bank-connections.store', $this->team), ['country' => 'FR', 'aspsp_name' => 'Inconnue'])
            ->assertRedirect(route('bank-connections.index', $this->team));

        $this->assertSame(0, BankConnection::count());
    }

    public function test_the_callback_opens_the_session_and_asks_to_map_the_accounts(): void
    {
        $connection = BankConnection::factory()->for($this->team)->for($this->user)->pending('state-ok')->create();
        Http::fake([
            'api.enablebanking.com/sessions' => Http::response($this->fixture('session')),
            'api.enablebanking.com/accounts/*/balances' => Http::response($this->fixture('balances')),
        ]);

        $this->get(route('bank.callback', ['state' => 'state-ok', 'code' => 'code-123']))
            ->assertRedirect(route('bank-connections.accounts', ['current_team' => $this->team, 'bank_connection' => $connection]));

        $connection->refresh();
        $this->assertSame(BankConnectionStatus::Active, $connection->status);
        $this->assertSame('4a5b2f6c-1e1a-4a8e-9c2d-2b8f0e6d3c11', $connection->session_id);
        $this->assertSame('2027-04-28', $connection->valid_until?->toDateString());
        $this->assertNull($connection->auth_state);
        $this->assertSame(['Compte de dépôt', 'Livret A'], $connection->bankAccounts()->orderBy('id')->pluck('name')->all());
        $this->assertSame('FR76 •••• 0189', $connection->bankAccounts()->orderBy('id')->first()?->iban_masked);
        $this->assertSame(1461_05, $connection->bankAccounts()->orderBy('id')->first()?->last_balance);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sessions') && $request['code'] === 'code-123');
    }

    public function test_the_callback_only_accepts_the_members_own_pending_connection(): void
    {
        $someoneElse = User::factory()->create();
        BankConnection::factory()->for($someoneElse->currentTeam)->for($someoneElse)->pending('their-state')->create();
        Http::fake();

        $this->get(route('bank.callback', ['state' => 'their-state', 'code' => 'code-123']))->assertRedirect(route('home'));

        Http::assertNothingSent();
    }

    public function test_a_refusal_at_the_bank_drops_the_pending_connection(): void
    {
        BankConnection::factory()->for($this->team)->for($this->user)->pending('state-ok')->create();

        $this->get(route('bank.callback', ['state' => 'state-ok', 'error' => 'access_denied', 'error_description' => 'Cancelled by user']))
            ->assertRedirect(route('bank-connections.index', $this->team))
            ->assertInertiaFlash('toast.message', 'The bank did not grant access: Cancelled by user');

        $this->assertSame(0, BankConnection::count());
    }

    public function test_mapping_links_existing_accounts_creates_new_ones_and_syncs(): void
    {
        $connection = BankConnection::factory()->for($this->team)->for($this->user)->create();
        $current = BankAccount::factory()->for($connection, 'connection')->create(['name' => 'Compte de dépôt']);
        $savings = BankAccount::factory()->for($connection, 'connection')->create(['name' => 'Livret A', 'last_balance' => 5000_00]);
        $ignored = BankAccount::factory()->for($connection, 'connection')->create(['name' => 'Carte différée']);
        $checking = Account::factory()->for($this->team)->create(['starting_date' => '2026-10-01']);
        Http::fake([
            'api.enablebanking.com/accounts/*/transactions*' => Http::response(['transactions' => [], 'continuation_key' => null]),
            'api.enablebanking.com/accounts/*/balances' => Http::response($this->fixture('balances')),
        ]);

        $this->put(route('bank-connections.save-accounts', ['current_team' => $this->team, 'bank_connection' => $connection]), ['mappings' => [
            ['bank_account_id' => $current->id, 'action' => 'existing', 'account_id' => $checking->id],
            ['bank_account_id' => $savings->id, 'action' => 'new', 'account_id' => null, 'name' => 'Livret A', 'type' => AccountType::Savings->value],
            ['bank_account_id' => $ignored->id, 'action' => 'ignore', 'account_id' => null, 'name' => 'Carte différée', 'type' => 'checking'],
        ]])->assertRedirect(route('bank-connections.index', $this->team))->assertSessionHasNoErrors();

        $this->assertSame($checking->id, $current->refresh()->account_id);
        $this->assertNull($ignored->refresh()->account_id);

        $livret = Account::where('name', 'Livret A')->sole();
        $this->assertSame($livret->id, $savings->refresh()->account_id);
        $this->assertSame(1461_05, (int) $livret->transactions()->sum('amount'));
        $this->assertSame('2026-10-29', $livret->starting_date->toDateString());
        $this->assertNotNull($connection->refresh()->last_synced_at);
    }

    public function test_a_new_account_starts_yesterday_so_todays_transactions_are_not_lost(): void
    {
        $connection = BankConnection::factory()->for($this->team)->for($this->user)->create();
        $bankAccount = BankAccount::factory()->for($connection, 'connection')->create(['name' => 'Compte joint']);
        $todays = [
            ['entry_reference' => 'avis-1', 'status' => 'PDNG', 'transaction_date' => '2026-10-30', 'credit_debit_indicator' => 'DBIT',
                'transaction_amount' => ['currency' => 'EUR', 'amount' => '4.18'], 'remittance_information' => ['ALDI']],
            ['entry_reference' => 'old-1', 'status' => 'BOOK', 'transaction_date' => '2026-10-29', 'booking_date' => '2026-10-29', 'credit_debit_indicator' => 'DBIT',
                'transaction_amount' => ['currency' => 'EUR', 'amount' => '10.00'], 'remittance_information' => ['BOULANGERIE']],
        ];
        Http::fake([
            'api.enablebanking.com/accounts/*/transactions*' => Http::response(['transactions' => $todays, 'continuation_key' => null]),
            'api.enablebanking.com/accounts/*/balances' => Http::response($this->fixture('balances')),
        ]);

        $this->put(route('bank-connections.save-accounts', ['current_team' => $this->team, 'bank_connection' => $connection]), ['mappings' => [
            ['bank_account_id' => $bankAccount->id, 'action' => 'new', 'account_id' => null, 'name' => 'Compte joint', 'type' => AccountType::Checking->value],
        ]])->assertSessionHasNoErrors();

        $account = Account::where('name', 'Compte joint')->sole();
        $this->assertSame('2026-10-29', $account->starting_date->toDateString());
        // This morning's balance, then today's pending payment imported by the sync.
        $this->assertSame(1465_23, (int) $account->transactions()->where('is_starting_balance', true)->sum('amount'));
        $this->assertSame(1461_05, (int) $account->transactions()->sum('amount'));
        $this->assertSame(2, $account->transactions()->count());
    }

    public function test_an_account_of_another_household_cannot_be_mapped(): void
    {
        $connection = BankConnection::factory()->for($this->team)->for($this->user)->create();
        $bankAccount = BankAccount::factory()->for($connection, 'connection')->create();
        $foreign = Account::factory()->create();

        $this->put(route('bank-connections.save-accounts', ['current_team' => $this->team, 'bank_connection' => $connection]), ['mappings' => [
            ['bank_account_id' => $bankAccount->id, 'action' => 'existing', 'account_id' => $foreign->id],
        ]])->assertSessionHasErrors('mappings.0.account_id');
    }

    public function test_renewing_keeps_the_mapping_and_the_history(): void
    {
        $checking = Account::factory()->for($this->team)->create();
        $connection = BankConnection::factory()->for($this->team)->for($this->user)->create(['session_id' => 'old-session', 'valid_until' => now()->addDays(3)]);
        $session = $this->fixture('session');
        $bankAccount = BankAccount::factory()->for($connection, 'connection')->create([
            'account_uid' => 'old-uid',
            'identification_hash' => $session['accounts'][0]['identification_hash'],
            'account_id' => $checking->id,
        ]);
        BankAccount::factory()->for($connection, 'connection')->create(['identification_hash' => $session['accounts'][1]['identification_hash']]);
        Transaction::factory()->for($this->team)->for($checking)->create(['bank_account_id' => $bankAccount->id, 'fingerprint' => 'f']);

        Http::fake([
            'api.enablebanking.com/aspsps*' => Http::response($this->fixture('aspsps')),
            'api.enablebanking.com/auth' => Http::response($this->fixture('auth')),
            'api.enablebanking.com/sessions' => Http::response($session),
            'api.enablebanking.com/sessions/old-session' => Http::response(['message' => 'OK']),
            'api.enablebanking.com/accounts/*' => Http::response(['transactions' => [], 'balances' => [], 'continuation_key' => null]),
        ]);

        $this->post(route('bank-connections.renew', ['current_team' => $this->team, 'bank_connection' => $connection]))
            ->assertRedirect($this->fixture('auth')['url']);

        $this->get(route('bank.callback', ['state' => $connection->refresh()->auth_state, 'code' => 'code-456']))
            ->assertRedirect(route('bank-connections.index', $this->team));

        $connection->refresh();
        $this->assertSame(BankConnectionStatus::Active, $connection->status);
        $this->assertSame('4a5b2f6c-1e1a-4a8e-9c2d-2b8f0e6d3c11', $connection->session_id);
        $this->assertSame(2, $connection->bankAccounts()->count());
        $this->assertSame('07cc67f4-45d6-494b-adac-09b5cbc7e2b5', $bankAccount->refresh()->account_uid);
        $this->assertSame($checking->id, $bankAccount->account_id);
        $this->assertSame(1, $bankAccount->transactions()->count());
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/sessions/old-session'));
    }

    public function test_only_the_member_who_connected_the_bank_can_renew_it(): void
    {
        $camille = User::factory()->create();
        $connection = BankConnection::factory()->for($this->team)->for($camille)->create();

        $this->post(route('bank-connections.renew', ['current_team' => $this->team, 'bank_connection' => $connection]))->assertForbidden();
    }

    public function test_sync_now_reads_the_bank_as_the_member(): void
    {
        $connection = BankConnection::factory()->for($this->team)->for($this->user)->create();
        BankAccount::factory()->for($connection, 'connection')->create(['account_id' => Account::factory()->for($this->team)->create()->id]);
        Http::fake([
            'api.enablebanking.com/accounts/*/transactions*' => Http::response(['transactions' => [], 'continuation_key' => null]),
            'api.enablebanking.com/accounts/*/balances' => Http::response($this->fixture('balances')),
        ]);

        $this->post(route('bank-connections.sync', ['current_team' => $this->team, 'bank_connection' => $connection]))
            ->assertRedirect()
            ->assertInertiaFlash('toast.message', 'Sync complete.');

        Http::assertSent(fn (Request $request) => $request->hasHeader('Psu-Ip-Address'));
    }

    public function test_disconnecting_revokes_the_consent_and_keeps_imported_transactions(): void
    {
        $connection = BankConnection::factory()->for($this->team)->for($this->user)->create(['session_id' => 'sess-1']);
        $bankAccount = BankAccount::factory()->for($connection, 'connection')->create();
        $transaction = Transaction::factory()->for($this->team)->create(['bank_account_id' => $bankAccount->id, 'fingerprint' => 'f']);
        Http::fake(['api.enablebanking.com/sessions/sess-1' => Http::response(['message' => 'OK'])]);

        $this->delete(route('bank-connections.destroy', ['current_team' => $this->team, 'bank_connection' => $connection]))
            ->assertRedirect(route('bank-connections.index', $this->team));

        $this->assertSame(0, BankConnection::count());
        $this->assertTrue($transaction->refresh()->isImported());
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_another_household_connection_is_not_found(): void
    {
        $foreign = BankConnection::factory()->create();

        $this->post(route('bank-connections.sync', ['current_team' => $this->team, 'bank_connection' => $foreign]))->assertNotFound();
        $this->delete(route('bank-connections.destroy', ['current_team' => $this->team, 'bank_connection' => $foreign]))->assertNotFound();
    }

    public function test_the_app_warns_seven_days_before_the_consent_ends(): void
    {
        BankConnection::factory()->for($this->team)->for($this->user)->create(['valid_until' => now()->addDays(8)]);
        $this->get(route('bank-connections.index', $this->team))->assertInertia(fn (AssertableInertia $page) => $page->has('bankRenewals', 0));

        BankConnection::factory()->for($this->team)->for($this->user)->create(['aspsp_name' => 'Boursorama', 'valid_until' => now()->addDays(6)]);
        $this->get(route('bank-connections.index', $this->team))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('bankRenewals', 1)
            ->where('bankRenewals.0.aspspName', 'Boursorama')
            ->where('bankRenewals.0.owner', 'Alex')
            ->where('bankRenewals.0.expired', false));
    }
}
