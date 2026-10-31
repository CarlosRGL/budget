<?php

namespace App\Actions\Transactions;

use App\Enums\PayeeCategoryMode;
use App\Models\Account;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a transaction typed by a member: a plain transaction, an inflow to
 * Ready to Assign, a split, or one side of a transfer (the other side follows).
 *
 * Saving is confirming: the transaction comes out approved by that member.
 */
class SaveTransaction
{
    /**
     * @param  array{
     *     account_id: int,
     *     date: string,
     *     payee_id?: int|null,
     *     payee_name?: string|null,
     *     transfer_account_id?: int|null,
     *     category_id?: int|null,
     *     inflow_to_rta?: bool,
     *     memo?: string|null,
     *     amount: int,
     *     cleared?: bool,
     *     splits?: array<int, array{category_id?: int|null, inflow_to_rta?: bool, memo?: string|null, amount: int}>,
     * }  $data
     */
    public function handle(User $by, array $data, ?Transaction $transaction = null): Transaction
    {
        return DB::transaction(function () use ($by, $data, $transaction) {
            $account = Account::findOrFail($data['account_id']);
            $transferTo = isset($data['transfer_account_id']) ? Account::findOrFail($data['transfer_account_id']) : null;
            $pair = $this->keepPairIfStillValid($transaction, $transferTo);

            $splits = $transferTo ? [] : ($data['splits'] ?? []);
            $leavesBudget = $transferTo && $account->on_budget && ! $transferTo->on_budget;
            $categoryId = match (true) {
                $splits !== [] => null,
                $transferTo !== null => $leavesBudget ? ($data['category_id'] ?? null) : null,
                default => $data['category_id'] ?? null,
            };
            $payeeId = $transferTo
                ? Payee::forTransferTo($transferTo)->id
                : $this->payeeId($account, $data);

            $attributes = [
                'team_id' => $account->team_id,
                'account_id' => $account->id,
                'date' => $data['date'],
                'payee_id' => $payeeId,
                'category_id' => $categoryId,
                'memo' => $data['memo'] ?? null,
                'amount' => $data['amount'],
                'cleared' => $data['cleared'] ?? false,
                'inflow_to_rta' => ! $transferTo && $splits === [] && $categoryId === null && ($data['inflow_to_rta'] ?? false),
                'approved_at' => now(),
                'approved_by' => $by->id,
                'review_reason' => null,
            ];

            if ($transaction) {
                $transaction->update($attributes);
            } else {
                $transaction = Transaction::create([...$attributes, 'created_by' => $by->id]);
            }

            $transaction->splits()->delete();
            if ($splits !== []) {
                $transaction->splits()->createMany(array_map(fn (array $split) => [
                    'category_id' => ($split['inflow_to_rta'] ?? false) ? null : ($split['category_id'] ?? null),
                    'inflow_to_rta' => $split['inflow_to_rta'] ?? false,
                    'memo' => $split['memo'] ?? null,
                    'amount' => $split['amount'],
                ], $splits));
            }

            if ($transferTo) {
                $this->saveOtherSide($by, $transaction, $account, $transferTo, $pair);
            }

            if ($payeeId && $categoryId && ! $transferTo) {
                Payee::whereKey($payeeId)
                    ->where('category_mode', PayeeCategoryMode::History)
                    ->update(['last_category_id' => $categoryId]);
            }

            return $transaction;
        });
    }

    /**
     * The other side of an existing transfer survives only if the transfer still goes
     * to the same account; otherwise it is removed.
     */
    private function keepPairIfStillValid(?Transaction $transaction, ?Account $transferTo): ?Transaction
    {
        $pair = $transaction?->transferPair;

        if ($pair && $pair->account_id !== $transferTo?->id) {
            $transaction->update(['transfer_pair_id' => null]);
            $pair->delete();

            return null;
        }

        return $pair;
    }

    private function saveOtherSide(User $by, Transaction $transaction, Account $account, Account $transferTo, ?Transaction $pair): void
    {
        $attributes = [
            'date' => $transaction->date,
            'payee_id' => Payee::forTransferTo($account)->id,
            'memo' => $transaction->memo,
            'amount' => -$transaction->amount,
            'approved_at' => now(),
            'approved_by' => $by->id,
        ];

        if ($pair) {
            $pair->update($attributes);

            return;
        }

        $pair = Transaction::create([
            ...$attributes,
            'team_id' => $transferTo->team_id,
            'account_id' => $transferTo->id,
            'transfer_pair_id' => $transaction->id,
            'created_by' => $by->id,
        ]);

        $transaction->update(['transfer_pair_id' => $pair->id]);
    }

    /**
     * @param  array{payee_id?: int|null, payee_name?: string|null}  $data
     */
    private function payeeId(Account $account, array $data): ?int
    {
        if (! empty($data['payee_id'])) {
            return (int) $data['payee_id'];
        }

        $name = trim((string) ($data['payee_name'] ?? ''));

        return $name === ''
            ? null
            : Payee::withoutGlobalScopes()->firstOrCreate(['team_id' => $account->team_id, 'name' => $name])->id;
    }
}
