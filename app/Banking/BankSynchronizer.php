<?php

namespace App\Banking;

use App\Enums\BankConnectionStatus;
use App\Enums\BankStatus;
use App\Enums\ReviewReason;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Payee;
use App\Models\PayeeRenameRule;
use App\Models\Scopes\CurrentTeamScope;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Imports the transactions and balances of a bank connection into the household
 * accounts its bank accounts are mapped to, without ever creating a transaction twice.
 *
 * Runs in queued jobs (no authenticated member): every query is scoped explicitly.
 */
class BankSynchronizer
{
    /**
     * Days fetched again before the previous sync, to catch late bookings.
     */
    public const OVERLAP_DAYS = 10;

    /**
     * How far apart a pending and a booked transaction (or a typed and an imported one) may be dated.
     */
    public const MATCH_DAYS = 5;

    /**
     * Days after its starting date during which a new account's starting
     * balance follows the bank balance (see alignStartingBalance()).
     */
    public const SETTLING_DAYS = 7;

    /**
     * Days a pending transaction may be missing from the bank's list before it
     * is flagged: some banks only list a card authorization for a few hours,
     * while it weighs on the balance until it is booked.
     */
    public const PENDING_DAYS = 7;

    /**
     * Balance types used for the bank balance, in order of preference.
     */
    private const BALANCE_TYPES = ['XPCD', 'CLBD', 'ITBD', 'ITAV', 'CLAV', 'OPBD', 'OPAV'];

    public function __construct(private EnableBankingClient $client) {}

    /**
     * Sync every mapped account of a connection. Errors are kept on the connection.
     *
     * @param  array<string, string>  $psuHeaders  Headers describing the member, when they asked for the sync.
     */
    public function sync(BankConnection $connection, array $psuHeaders = []): bool
    {
        try {
            $bankAccounts = BankAccount::withoutGlobalScope(CurrentTeamScope::class)
                ->whereBelongsTo($connection, 'connection')
                ->whereNotNull('account_id')
                ->whereNotNull('account_uid')
                ->get();

            foreach ($bankAccounts as $bankAccount) {
                $this->syncAccount($bankAccount, $psuHeaders);
            }

            $connection->update(['last_synced_at' => now(), 'last_error' => null]);

            return true;
        } catch (EnableBankingException $exception) {
            Log::warning('Bank sync failed', ['connection' => $connection->id, 'error' => $exception->getMessage()]);

            $connection->update([
                'last_error' => $exception->getMessage(),
                ...($exception->isExpiredConsent() ? ['status' => BankConnectionStatus::Expired] : []),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, string>  $psuHeaders
     */
    private function syncAccount(BankAccount $bankAccount, array $psuHeaders): void
    {
        $account = Account::withoutGlobalScope(CurrentTeamScope::class)->findOrFail($bankAccount->account_id);
        $dateFrom = $this->dateFrom($account, $bankAccount);

        $transactions = $this->client->transactions((string) $bankAccount->account_uid, $dateFrom, $psuHeaders);
        $balance = self::preferredBalance($this->client->balances((string) $bankAccount->account_uid, $psuHeaders));

        DB::transaction(function () use ($bankAccount, $account, $transactions, $dateFrom, $balance) {
            $this->import($bankAccount, $account, $transactions, $dateFrom);
            $this->alignStartingBalance($account, $balance);

            $bankAccount->update([
                'last_balance' => $balance ?? $bankAccount->last_balance,
                'last_balance_at' => now(),
            ]);
        });
    }

    /**
     * A card payment can weigh on the bank balance a day or two before the bank
     * lists it, with the date it is booked. A new account starting from that
     * balance then counts it twice once it is imported. During its first days,
     * the starting balance is therefore the bank balance minus everything the
     * account holds since, so that both agree.
     */
    private function alignStartingBalance(Account $account, ?int $balance): void
    {
        if ($balance === null || CarbonImmutable::parse($account->starting_date)->addDays(self::SETTLING_DAYS)->lt(CarbonImmutable::today())) {
            return;
        }

        $transactions = Transaction::withoutGlobalScope(CurrentTeamScope::class)->whereBelongsTo($account);
        $start = (clone $transactions)->where('is_starting_balance', true)->first();

        if ($start === null) {
            return;
        }

        $start->update(['amount' => $balance - (int) (clone $transactions)->whereKeyNot($start->id)->sum('amount')]);
    }

    /**
     * Only transactions after the account's starting date are imported, and each
     * sync goes back OVERLAP_DAYS before the previous one of this bank account
     * (`last_balance_at` is set by every successful sync). Never after today:
     * banks refuse a future date_from (an account starting today asks for today,
     * and import() still skips the starting date itself).
     */
    public function dateFrom(Account $account, BankAccount $bankAccount): CarbonImmutable
    {
        $afterStart = CarbonImmutable::parse($account->starting_date)->addDay()->min(CarbonImmutable::today());

        if ($bankAccount->last_balance_at === null) {
            return $afterStart;
        }

        return CarbonImmutable::parse($bankAccount->last_balance_at)->startOfDay()->subDays(self::OVERLAP_DAYS)->max($afterStart);
    }

    /**
     * Create, update or flag the household transactions from the bank's list.
     *
     * Matching, in order: same bank id; same fingerprint; then a pending transaction
     * of the same amount within MATCH_DAYS (banks often change the id when booking).
     *
     * @param  list<array<string, mixed>>  $apiTransactions
     * @return array{created: int, updated: int, flagged: int}
     */
    public function import(BankAccount $bankAccount, Account $account, array $apiTransactions, CarbonInterface $dateFrom): array
    {
        $incoming = $this->withoutBookedNotices(collect($apiTransactions)
            ->map(fn (array $data) => BankTransaction::fromApi($data))
            ->filter(fn (?BankTransaction $transaction) => $transaction !== null && $transaction->date->gt($account->starting_date))
            ->values());

        $known = $this->importedTransactions($account, $incoming, $dateFrom);
        $claimed = [];
        $matches = [];

        foreach ($incoming as $index => $bankTransaction) {
            $match = ($bankTransaction->externalId !== null
                    ? $known->first(fn (Transaction $transaction) => ! isset($claimed[$transaction->id]) && $transaction->external_id === $bankTransaction->externalId)
                    : null)
                ?? $known->first(fn (Transaction $transaction) => ! isset($claimed[$transaction->id]) && $transaction->fingerprint === $bankTransaction->fingerprint);

            if ($match) {
                $claimed[$match->id] = true;
                $matches[$index] = $match;
            }
        }

        foreach ($incoming as $index => $bankTransaction) {
            if (isset($matches[$index])) {
                continue;
            }

            $match = $known
                ->filter(fn (Transaction $transaction) => ! isset($claimed[$transaction->id])
                    && $transaction->bank_status === BankStatus::Pending
                    && $transaction->amount === $bankTransaction->amount
                    && abs($transaction->date->diffInDays($bankTransaction->date)) <= self::MATCH_DAYS)
                ->sortBy(fn (Transaction $transaction) => abs($transaction->date->diffInDays($bankTransaction->date)))
                ->first();

            if ($match) {
                $claimed[$match->id] = true;
                $matches[$index] = $match;
            }
        }

        $counts = ['created' => 0, 'updated' => 0, 'flagged' => 0];

        foreach ($incoming as $index => $bankTransaction) {
            if (isset($matches[$index])) {
                $counts['updated'] += (int) $this->update($matches[$index], $bankTransaction, $account);
            } else {
                $this->create($bankAccount, $account, $bankTransaction);
                $counts['created']++;
            }
        }

        $counts['flagged'] = $this->flagRemoved($known, $claimed, $dateFrom);

        return $counts;
    }

    /**
     * Some banks (Boursorama) keep listing the card notice of a pending payment,
     * under its own id and label, for a day or two after the payment is booked.
     * A pending transaction with the amount and date of a booked one of the same
     * list is that notice: it is left out, so the booked one takes over the
     * transaction imported from it instead of coming as a second one.
     *
     * @param  Collection<int, BankTransaction>  $incoming
     * @return Collection<int, BankTransaction>
     */
    private function withoutBookedNotices(Collection $incoming): Collection
    {
        $booked = $incoming->filter(fn (BankTransaction $transaction) => $transaction->status === BankStatus::Booked);
        $paired = [];

        return $incoming->reject(function (BankTransaction $transaction) use ($booked, &$paired) {
            if ($transaction->status !== BankStatus::Pending) {
                return false;
            }

            $twin = $booked->search(fn (BankTransaction $candidate, int $index) => ! isset($paired[$index])
                && $candidate->amount === $transaction->amount
                && $candidate->date->isSameDay($transaction->date));

            if ($twin === false) {
                return false;
            }

            $paired[$twin] = true;

            return true;
        })->values();
    }

    /**
     * Transactions of the account already linked to the bank that the incoming list may match.
     *
     * @param  Collection<int, BankTransaction>  $incoming
     * @return Collection<int, Transaction>
     */
    private function importedTransactions(Account $account, Collection $incoming, CarbonInterface $dateFrom): Collection
    {
        $earliest = $incoming->min(fn (BankTransaction $transaction) => $transaction->date->toDateString()) ?? $dateFrom->toDateString();
        $since = CarbonImmutable::parse(min($earliest, $dateFrom->toDateString()))->subDays(self::OVERLAP_DAYS);

        $externalIds = $incoming->pluck('externalId')->filter()->values()->all();

        return Transaction::withoutGlobalScope(CurrentTeamScope::class)
            ->where('account_id', $account->id)
            ->whereNotNull('fingerprint')
            ->where(fn ($query) => $query
                ->where('date', '>=', $since->toDateString())
                ->when($externalIds !== [], fn ($query) => $query->orWhereIn('external_id', $externalIds)))
            ->orderBy('id')
            ->get();
    }

    /**
     * Bring a known transaction up to date with the bank. Members' choices (payee,
     * category, memo, date) are kept; an approved transaction whose amount changed
     * goes back to the inbox.
     */
    private function update(Transaction $transaction, BankTransaction $bankTransaction, Account $account): bool
    {
        $previousPayeeId = $transaction->payee_id;

        if ($bankTransaction->status === BankStatus::Booked && $transaction->bank_status === BankStatus::Pending) {
            $this->followBookedLabel($transaction, $bankTransaction, $account);
        }

        $transaction->fill([
            'external_id' => $bankTransaction->externalId ?? $transaction->external_id,
            'fingerprint' => $bankTransaction->fingerprint,
            'bank_status' => $bankTransaction->status,
            'raw_label' => $bankTransaction->rawLabel,
            'card_suffix' => $bankTransaction->cardSuffix ?? $transaction->card_suffix,
        ]);

        if ($bankTransaction->status === BankStatus::Booked && $transaction->getOriginal('bank_status') === BankStatus::Pending) {
            $transaction->cleared = true;
        }

        if ($transaction->review_reason === ReviewReason::RemovedByBank) {
            $transaction->review_reason = null;
        }

        if ($transaction->amount !== $bankTransaction->amount) {
            if ($transaction->isApproved()) {
                $transaction->fill(['approved_at' => null, 'approved_by' => null, 'review_reason' => ReviewReason::AmountChanged]);
            }

            $transaction->amount = $bankTransaction->amount;
        }

        if (! $transaction->isDirty() || ! $transaction->save()) {
            return false;
        }

        if ($transaction->payee_id !== $previousPayeeId) {
            Payee::deleteUnused([$previousPayeeId]);
        }

        return true;
    }

    /**
     * A pending card payment often carries another label than once booked (the
     * card network's merchant name, or a cash machine code), so the rename rules
     * may not have recognised it. Unless a member already reviewed it or chose
     * its payee, it takes the payee of its booked label, and that payee's
     * category when it still had the one of the first payee.
     */
    private function followBookedLabel(Transaction $transaction, BankTransaction $bankTransaction, Account $account): void
    {
        if ($transaction->isApproved() || $transaction->raw_label === null) {
            return;
        }

        $automatic = $this->findPayee($account->team_id, $transaction->raw_label, BankLabel::clean($transaction->raw_label)['label']);

        if ($automatic === null || $automatic->id !== $transaction->payee_id) {
            return;
        }

        $payee = $this->payeeFor($account->team_id, $bankTransaction);

        if ($payee->id === $automatic->id) {
            return;
        }

        $transaction->payee_id = $payee->id;

        $keepsAutomaticCategory = $transaction->category_id === null || $transaction->category_id === $automatic->defaultCategoryId();

        if ($keepsAutomaticCategory && $transaction->transfer_pair_id === null && ! $transaction->inflow_to_rta && ! $transaction->splits()->exists()) {
            $transaction->category_id = $account->on_budget ? $payee->defaultCategoryId() : null;
        }
    }

    private function create(BankAccount $bankAccount, Account $account, BankTransaction $bankTransaction): void
    {
        $payee = $this->payeeFor($account->team_id, $bankTransaction);

        Transaction::create([
            'team_id' => $account->team_id,
            'account_id' => $account->id,
            'date' => $bankTransaction->date,
            'payee_id' => $payee->id,
            'category_id' => $account->on_budget ? $payee->defaultCategoryId() : null,
            'amount' => $bankTransaction->amount,
            'cleared' => $bankTransaction->status === BankStatus::Booked,
            'approved_at' => null,
            'bank_account_id' => $bankAccount->id,
            'external_id' => $bankTransaction->externalId,
            'fingerprint' => $bankTransaction->fingerprint,
            'bank_status' => $bankTransaction->status,
            'raw_label' => $bankTransaction->rawLabel,
            'card_suffix' => $bankTransaction->cardSuffix,
            'review_reason' => $this->typedByHand($account, $bankTransaction) ? ReviewReason::PossibleDuplicate : null,
        ]);
    }

    /**
     * The payee of a rename rule whose text is in the raw label, else the cleaned label.
     */
    private function payeeFor(int $teamId, BankTransaction $bankTransaction): Payee
    {
        return $this->findPayee($teamId, $bankTransaction->rawLabel, $bankTransaction->label)
            ?? Payee::withoutGlobalScope(CurrentTeamScope::class)->create([
                'team_id' => $teamId,
                'name' => mb_substr($bankTransaction->label, 0, 255),
            ]);
    }

    /**
     * The payee the import gives to a label, if it already exists.
     */
    private function findPayee(int $teamId, string $rawLabel, string $label): ?Payee
    {
        $rule = PayeeRenameRule::withoutGlobalScope(CurrentTeamScope::class)
            ->with('payee')
            ->where('team_id', $teamId)
            ->orderBy('id')
            ->get()
            ->first(fn (PayeeRenameRule $rule) => $rule->matches($rawLabel));

        return $rule->payee ?? Payee::withoutGlobalScope(CurrentTeamScope::class)
            ->where('team_id', $teamId)
            ->where('name', mb_substr($label, 0, 255))
            ->first();
    }

    /**
     * Determine if a member already typed this transaction by hand.
     */
    private function typedByHand(Account $account, BankTransaction $bankTransaction): bool
    {
        return Transaction::withoutGlobalScope(CurrentTeamScope::class)
            ->where('account_id', $account->id)
            ->whereNull('fingerprint')
            ->where('is_starting_balance', false)
            ->where('amount', $bankTransaction->amount)
            ->whereBetween('date', [
                $bankTransaction->date->copy()->subDays(self::MATCH_DAYS)->toDateString(),
                $bankTransaction->date->copy()->addDays(self::MATCH_DAYS)->toDateString(),
            ])
            ->exists();
    }

    /**
     * Pending transactions of the fetched period the bank no longer returns, and
     * still not booked PENDING_DAYS later, go back to the inbox, flagged; they
     * are never deleted automatically.
     *
     * @param  Collection<int, Transaction>  $known
     * @param  array<int, true>  $claimed
     */
    private function flagRemoved(Collection $known, array $claimed, CarbonInterface $dateFrom): int
    {
        $removed = $known->filter(fn (Transaction $transaction) => ! isset($claimed[$transaction->id])
            && $transaction->bank_status === BankStatus::Pending
            && $transaction->review_reason === null
            && $transaction->date->toDateString() >= $dateFrom->toDateString()
            && $transaction->date->lt(CarbonImmutable::today()->subDays(self::PENDING_DAYS)));

        $removed->each(fn (Transaction $transaction) => $transaction->update([
            'review_reason' => ReviewReason::RemovedByBank,
            'approved_at' => null,
            'approved_by' => null,
        ]));

        return $removed->count();
    }

    /**
     * @param  list<array<string, mixed>>  $balances
     */
    public static function preferredBalance(array $balances): ?int
    {
        $byType = collect($balances)->keyBy('balance_type');

        foreach (self::BALANCE_TYPES as $type) {
            if ($byType->has($type)) {
                return BankTransaction::cents((string) $byType[$type]['balance_amount']['amount']);
            }
        }

        $first = $balances[0] ?? null;

        return $first ? BankTransaction::cents((string) $first['balance_amount']['amount']) : null;
    }
}
