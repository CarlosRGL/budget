<?php

namespace App\Banking;

use App\Actions\Accounts\CreateAccount;
use App\Enums\AccountType;
use App\Enums\BankConnectionStatus;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Scopes\CurrentTeamScope;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The life of a bank connection: authorization at the bank, session, mapping of
 * its accounts, renewal of the consent and disconnection.
 */
class BankConnector
{
    /**
     * Consent asked when the bank does not say how long it may last.
     */
    public const DEFAULT_CONSENT_DAYS = 90;

    public function __construct(private EnableBankingClient $client, private CreateAccount $createAccount) {}

    /**
     * Banks of a country, cached for a day.
     *
     * @return list<array{name: string, logo: string|null, maximumConsentDays: int}>
     */
    public function banks(string $country): array
    {
        $country = strtoupper($country);

        return array_values(Cache::remember("enablebanking.aspsps.{$country}", now()->addDay(), fn () => collect($this->client->aspsps($country))
            ->map(fn (array $aspsp) => [
                'name' => $aspsp['name'],
                'logo' => $aspsp['logo'] ?? null,
                'maximumConsentDays' => isset($aspsp['maximum_consent_validity'])
                    ? max(1, intdiv((int) $aspsp['maximum_consent_validity'], 86400))
                    : self::DEFAULT_CONSENT_DAYS,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all()));
    }

    /**
     * Record a pending connection and get the URL where the member authorizes access.
     */
    public function start(User $user, string $aspspName, string $country): string
    {
        BankConnection::withoutGlobalScope(CurrentTeamScope::class)
            ->where('user_id', $user->id)
            ->where('status', BankConnectionStatus::Pending)
            ->delete();

        $connection = BankConnection::create([
            'team_id' => $user->current_team_id,
            'user_id' => $user->id,
            'aspsp_name' => $aspspName,
            'aspsp_country' => strtoupper($country),
            'status' => BankConnectionStatus::Pending,
        ]);

        try {
            return $this->authorize($connection);
        } catch (EnableBankingException $exception) {
            $connection->delete();

            throw $exception;
        }
    }

    /**
     * Get the URL where the member gives a new consent for an existing connection.
     */
    public function renew(BankConnection $connection): string
    {
        return $this->authorize($connection);
    }

    /**
     * The member is back from the bank: open the session and record its accounts.
     * On renewal, bank accounts are found again by their identification hash so
     * that their mapping (and the import history) stays.
     *
     * @return int How many bank accounts are new to this connection.
     */
    public function complete(BankConnection $connection, string $code, Request $request): int
    {
        $session = $this->client->createSession($code);
        $previousSession = $connection->session_id;

        $added = DB::transaction(function () use ($connection, $session) {
            $added = 0;

            $connection->update([
                'session_id' => $session['session_id'],
                'valid_until' => Carbon::parse($session['access']['valid_until']),
                'status' => BankConnectionStatus::Active,
                'auth_state' => null,
                'last_error' => null,
            ]);

            foreach ($session['accounts'] as $data) {
                $hash = $data['identification_hash'] ?? null;
                $bankAccount = ($hash ? BankAccount::withoutGlobalScope(CurrentTeamScope::class)
                    ->whereBelongsTo($connection, 'connection')
                    ->where('identification_hash', $hash)
                    ->first() : null) ?? new BankAccount(['team_id' => $connection->team_id, 'bank_connection_id' => $connection->id]);

                $bankAccount->fill([
                    'account_uid' => $data['uid'],
                    'identification_hash' => $hash,
                    'iban_masked' => BankAccount::maskIban($data['account_id']['iban'] ?? null),
                    'name' => BankAccount::nameFrom($data),
                    'currency' => ($data['currency'] ?? 'XXX') === 'XXX' ? null : $data['currency'],
                ]);
                $added += (int) ! $bankAccount->exists;
                $bankAccount->save();
            }

            return $added;
        });

        if ($previousSession && $previousSession !== $session['session_id']) {
            rescue(fn () => $this->client->deleteSession($previousSession), report: false);
        }

        $this->fetchBalancesOfUnmapped($connection, self::psuHeaders($request));

        return $added;
    }

    /**
     * Link each bank account to an existing account, a new one, or nothing.
     *
     * @param  list<array{bank_account_id: int, action: string, account_id: int|null, name: string|null, type: string|null}>  $mappings
     */
    public function map(User $user, BankConnection $connection, array $mappings): void
    {
        DB::transaction(function () use ($user, $connection, $mappings) {
            foreach ($mappings as $mapping) {
                $bankAccount = $connection->bankAccounts()->findOrFail($mapping['bank_account_id']);

                $accountId = match ($mapping['action']) {
                    'existing' => $mapping['account_id'],
                    'new' => $this->newAccount(
                        $user,
                        $bankAccount,
                        $mapping['name'] ?? $bankAccount->label(),
                        AccountType::tryFrom($mapping['type'] ?? '') ?? AccountType::Checking,
                    )->id,
                    default => null,
                };

                $bankAccount->update(['account_id' => $accountId]);
            }
        });
    }

    /**
     * Revoke the consent at the bank (when still possible) and forget the connection.
     */
    public function disconnect(BankConnection $connection): void
    {
        if ($connection->session_id) {
            rescue(fn () => $this->client->deleteSession((string) $connection->session_id), report: false);
        }

        $connection->delete();
    }

    /**
     * Headers telling the bank the member is present, so the read does not count
     * in the few unattended reads allowed per day.
     *
     * @return array<string, string>
     */
    public static function psuHeaders(Request $request): array
    {
        return array_filter([
            'Psu-Ip-Address' => (string) $request->ip(),
            'Psu-User-Agent' => (string) $request->userAgent(),
        ]);
    }

    private function authorize(BankConnection $connection): string
    {
        $state = Str::random(40);
        $connection->update(['auth_state' => $state]);

        $days = collect(rescue(fn () => $this->banks($connection->aspsp_country), [], report: false))
            ->firstWhere('name', $connection->aspsp_name)['maximumConsentDays'] ?? self::DEFAULT_CONSENT_DAYS;

        return $this->client->startAuthorization(
            $connection->aspsp_name,
            $connection->aspsp_country,
            $state,
            route('bank.callback'),
            now()->addDays(min($days, 180)),
        )['url'];
    }

    /**
     * A new account starts yesterday with the balance of this morning: the
     * bank's balance minus today's transactions, which the sync then imports.
     *
     * Starting today with the current balance would lose for good what the
     * bank lists later with today's date (card payments shown a few hours
     * after being counted in the balance): the sync skips the starting date.
     */
    private function newAccount(User $user, BankAccount $bankAccount, string $name, AccountType $type): Account
    {
        $today = Carbon::today();
        $balance = $bankAccount->last_balance ?? 0;
        $todays = 0;

        $read = rescue(fn () => [
            BankSynchronizer::preferredBalance($this->client->balances((string) $bankAccount->account_uid)),
            $this->client->transactions((string) $bankAccount->account_uid, $today),
        ], null, report: false);

        if ($read === null) {
            return $this->createAccount->handle($user, $name, $type, $type->isOnBudgetByDefault(), $balance, $today);
        }

        [$freshBalance, $transactions] = $read;
        $balance = $freshBalance ?? $balance;

        foreach ($transactions as $data) {
            $transaction = BankTransaction::fromApi($data);

            if ($transaction !== null && $transaction->date->gte($today)) {
                $todays += $transaction->amount;
            }
        }

        return $this->createAccount->handle($user, $name, $type, $type->isOnBudgetByDefault(), $balance - $todays, $today->copy()->subDay());
    }

    /**
     * @param  array<string, string>  $psuHeaders
     */
    private function fetchBalancesOfUnmapped(BankConnection $connection, array $psuHeaders): void
    {
        $connection->bankAccounts()->withoutGlobalScope(CurrentTeamScope::class)->whereNull('account_id')->get()
            ->each(fn (BankAccount $bankAccount) => rescue(function () use ($bankAccount, $psuHeaders) {
                $balances = $this->client->balances((string) $bankAccount->account_uid, $psuHeaders);
                $balance = BankSynchronizer::preferredBalance($balances);

                $bankAccount->update(['last_balance' => $balance, 'last_balance_at' => null]);
            }, report: false));
    }
}
