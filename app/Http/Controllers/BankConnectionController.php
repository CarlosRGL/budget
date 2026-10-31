<?php

namespace App\Http\Controllers;

use App\Banking\BankConnector;
use App\Banking\EnableBankingClient;
use App\Banking\EnableBankingException;
use App\Enums\AccountType;
use App\Enums\BankConnectionStatus;
use App\Http\Requests\Banking\SaveBankAccountsRequest;
use App\Http\Requests\Banking\StoreBankConnectionRequest;
use App\Jobs\SyncBankConnection;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The household's bank connections: connect a bank, map its accounts, sync,
 * renew the consent and disconnect. Route model binding only finds connections
 * of the current household (BelongsToTeam global scope).
 */
class BankConnectionController extends Controller
{
    public function index(Request $request, Team $current_team, EnableBankingClient $client, BankConnector $connector): Response
    {
        $connections = BankConnection::query()
            ->with(['user', 'bankAccounts.account'])
            ->where('status', '!=', BankConnectionStatus::Pending)
            ->orderBy('created_at')
            ->get();

        $balances = Account::query()
            ->whereIn('id', $connections->flatMap->bankAccounts->pluck('account_id')->filter())
            ->withSum('transactions', 'amount')
            ->pluck('transactions_sum_amount', 'id');

        return Inertia::render('bank-connections/index', [
            'configured' => $client->isConfigured(),
            'connections' => $connections->map(fn (BankConnection $connection) => [
                'id' => $connection->id,
                'aspspName' => $connection->aspsp_name,
                'country' => $connection->aspsp_country,
                'owner' => $connection->user->name,
                'isOwner' => $connection->user_id === $request->user()->id,
                'expired' => $connection->isExpired(),
                'expiresSoon' => $connection->valid_until?->lte(now()->addDays(BankConnection::WARNING_DAYS)) ?? false,
                'validUntil' => $connection->valid_until?->toIso8601String(),
                'lastSyncedAt' => $connection->last_synced_at?->toIso8601String(),
                'lastError' => $connection->last_error,
                'accounts' => $connection->bankAccounts->map(fn (BankAccount $bankAccount) => [
                    'id' => $bankAccount->id,
                    'name' => $bankAccount->label(),
                    'ibanMasked' => $bankAccount->iban_masked,
                    'account' => $bankAccount->account ? ['id' => $bankAccount->account->id, 'name' => $bankAccount->account->name] : null,
                    'bankBalance' => $bankAccount->last_balance,
                    'balance' => $bankAccount->account_id ? (int) ($balances[$bankAccount->account_id] ?? 0) : null,
                ])->all(),
            ])->all(),
            'aspsps' => Inertia::optional(fn () => $request->filled('country')
                ? $connector->banks((string) $request->query('country'))
                : []),
        ]);
    }

    /**
     * The step-by-step guide to set up EnableBanking on this instance, with
     * what is already in place.
     */
    public function setup(Team $current_team, EnableBankingClient $client): Response
    {
        return Inertia::render('bank-connections/setup', [
            'status' => [
                ...$client->setupStatus(),
                'connected' => BankConnection::query()->where('status', '!=', BankConnectionStatus::Pending)->exists(),
            ],
            'callbackUrl' => route('bank.callback'),
        ]);
    }

    /**
     * Start connecting a bank: the member is sent to EnableBanking, then to the bank.
     */
    public function store(StoreBankConnectionRequest $request, BankConnector $connector): HttpResponse
    {
        try {
            $url = $connector->start($request->user(), $request->validated('aspsp_name'), $request->validated('country'));
        } catch (EnableBankingException $exception) {
            return $this->failed($exception);
        }

        return Inertia::location($url);
    }

    /**
     * Ask the bank for a new consent; the bank accounts stay mapped and history is kept.
     */
    public function renew(Request $request, Team $current_team, BankConnection $bankConnection, BankConnector $connector): HttpResponse
    {
        abort_unless($bankConnection->user_id === $request->user()->id, 403, __('Only the member who connected this bank can renew access.'));

        try {
            $url = $connector->renew($bankConnection);
        } catch (EnableBankingException $exception) {
            return $this->failed($exception);
        }

        return Inertia::location($url);
    }

    /**
     * "Synchroniser maintenant": sync right away, as the member is present.
     */
    public function sync(Request $request, Team $current_team, BankConnection $bankConnection): RedirectResponse
    {
        SyncBankConnection::dispatchSync($bankConnection, BankConnector::psuHeaders($request));

        $error = $bankConnection->refresh()->last_error;

        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => __('Sync complete.')]
            : ['type' => 'error', 'message' => __('Sync failed: :error', ['error' => $error])]);

        return back();
    }

    /**
     * Revoke the consent and forget the connection. Imported transactions stay.
     */
    public function destroy(Team $current_team, BankConnection $bankConnection, BankConnector $connector): RedirectResponse
    {
        $connector->disconnect($bankConnection);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection to :bank removed.', ['bank' => $bankConnection->aspsp_name])]);

        return to_route('bank-connections.index');
    }

    /**
     * Map each bank account to an existing or a new household account.
     */
    public function accounts(Team $current_team, BankConnection $bankConnection): Response
    {
        $mapped = BankAccount::query()->whereNotNull('account_id')->whereKeyNot($bankConnection->bankAccounts->modelKeys())->pluck('account_id');

        return Inertia::render('bank-connections/accounts', [
            'connection' => ['id' => $bankConnection->id, 'aspspName' => $bankConnection->aspsp_name],
            'bankAccounts' => $bankConnection->bankAccounts->map(fn (BankAccount $bankAccount) => [
                'id' => $bankAccount->id,
                'name' => $bankAccount->label(),
                'isCard' => $bankAccount->isCard(),
                'ibanMasked' => $bankAccount->iban_masked,
                'currency' => $bankAccount->currency,
                'accountId' => $bankAccount->account_id,
                'bankBalance' => $bankAccount->last_balance,
            ])->all(),
            'accounts' => Account::query()
                ->whereNull('closed_at')
                ->whereNotIn('id', $mapped)
                ->orderBy('sort')
                ->get()
                ->map(fn (Account $account) => ['id' => $account->id, 'name' => $account->name])
                ->all(),
            'accountTypes' => collect(AccountType::cases())
                ->map(fn (AccountType $type) => ['value' => $type->value, 'label' => $type->label()])
                ->all(),
        ]);
    }

    public function saveAccounts(SaveBankAccountsRequest $request, Team $current_team, BankConnection $bankConnection, BankConnector $connector): RedirectResponse
    {
        $connector->map($request->user(), $bankConnection, $request->mappings());

        SyncBankConnection::dispatchSync($bankConnection, BankConnector::psuHeaders($request));

        $error = $bankConnection->refresh()->last_error;

        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => __('Accounts linked and synced.')]
            : ['type' => 'error', 'message' => __('Accounts linked, but the sync failed: :error', ['error' => $error])]);

        return to_route('bank-connections.index');
    }

    private function failed(EnableBankingException $exception): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('Could not connect to the bank: :error', ['error' => $exception->getMessage()])]);

        return back();
    }
}
