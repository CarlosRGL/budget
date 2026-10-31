<?php

namespace App\Support;

use App\Budget\BudgetCalculator;
use App\Enums\BankStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Payee;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data of the transactions list ("register") of one account or of every account,
 * and of the pickers used to type a transaction.
 */
class TransactionRegister
{
    /**
     * How many transactions a page shows at most, newest first.
     */
    public const LIMIT = 500;

    public function __construct(private BudgetCalculator $calculator) {}

    /**
     * @return array{filter: string|null, balances: array{cleared: int, uncleared: int, working: int}|null, transactions: array<int, array<string, mixed>>, truncated: bool}
     */
    public function forAccount(?Account $account, ?string $filter): array
    {
        $query = Transaction::query()
            ->with(['account', 'payee', 'category', 'splits.category', 'transferPair.account', 'approver'])
            ->when($account, fn (Builder $query) => $query->whereBelongsTo($account))
            ->when($filter === 'inbox', fn (Builder $query) => $query->inInbox())
            ->orderByDesc('date')
            ->orderByDesc('id');

        $transactions = $query->limit(self::LIMIT + 1)->get();

        return [
            'filter' => $filter === 'inbox' ? 'inbox' : null,
            'balances' => $account ? $this->balances($account) : null,
            'transactions' => $transactions->take(self::LIMIT)->map($this->present(...))->values()->all(),
            'truncated' => $transactions->count() > self::LIMIT,
        ];
    }

    /**
     * Everything needed to type a transaction: payees with their usual category,
     * categories with what is available in them this month, and accounts for transfers.
     *
     * @return array<string, mixed>
     */
    public function pickers(Team $team): array
    {
        $budget = $this->calculator->forMonth($team, CarbonImmutable::now()->startOfMonth());

        return [
            'readyToAssign' => $budget->readyToAssign,
            'payees' => Payee::query()
                ->whereNull('transfer_account_id')
                ->orderBy('name')
                ->get(['id', 'name', 'last_category_id', 'category_mode'])
                ->map(fn (Payee $payee) => ['id' => $payee->id, 'name' => $payee->name, 'lastCategoryId' => $payee->defaultCategoryId()])
                ->all(),
            'categoryGroups' => CategoryGroup::query()
                ->with('categories')
                ->orderBy('sort')
                ->get()
                ->map(fn (CategoryGroup $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'hidden' => $group->hidden,
                    'categories' => $group->categories->map(fn (Category $category) => [
                        'id' => $category->id,
                        'name' => $category->name,
                        'hidden' => $category->hidden,
                        'available' => $budget->category($category)->available,
                    ])->all(),
                ])
                ->all(),
            'accounts' => Account::query()
                ->orderBy('sort')
                ->get()
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'onBudget' => $account->on_budget,
                    'closed' => $account->isClosed(),
                ])
                ->all(),
        ];
    }

    /**
     * @return array{cleared: int, uncleared: int, working: int}
     */
    private function balances(Account $account): array
    {
        $cleared = (int) $account->transactions()->where('cleared', true)->sum('amount');
        $uncleared = (int) $account->transactions()->where('cleared', false)->sum('amount');

        return ['cleared' => $cleared, 'uncleared' => $uncleared, 'working' => $cleared + $uncleared];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Transaction $transaction): array
    {
        $pairAccount = $transaction->transferPair?->account;

        return [
            'id' => $transaction->id,
            'account' => ['id' => $transaction->account->id, 'name' => $transaction->account->name, 'onBudget' => $transaction->account->on_budget],
            'date' => $transaction->date->toDateString(),
            'payee' => $transaction->payee && ! $pairAccount ? ['id' => $transaction->payee->id, 'name' => $transaction->payee->name] : null,
            'transferAccount' => $pairAccount ? ['id' => $pairAccount->id, 'name' => $pairAccount->name, 'onBudget' => $pairAccount->on_budget] : null,
            'category' => $transaction->category ? ['id' => $transaction->category->id, 'name' => $transaction->category->name] : null,
            'inflowToRta' => $transaction->inflow_to_rta,
            'isStartingBalance' => $transaction->is_starting_balance,
            'memo' => $transaction->memo,
            'amount' => $transaction->amount,
            'cleared' => $transaction->cleared,
            'approved' => $transaction->isApproved(),
            'approvedBy' => $transaction->approver?->name,
            'needsCategory' => $this->needsCategory($transaction),
            'imported' => $transaction->isImported(),
            'bankPending' => $transaction->bank_status === BankStatus::Pending,
            'rawLabel' => $transaction->raw_label,
            'cardSuffix' => $transaction->card_suffix,
            'reviewReason' => $transaction->review_reason?->value,
            'splits' => $transaction->splits->map(fn (TransactionSplit $split) => [
                'id' => $split->id,
                'category' => $split->category ? ['id' => $split->category->id, 'name' => $split->category->name] : null,
                'inflowToRta' => $split->inflow_to_rta,
                'memo' => $split->memo,
                'amount' => $split->amount,
            ])->all(),
        ];
    }

    /**
     * Same rule as Transaction::needingCategory(), on an already loaded transaction.
     */
    private function needsCategory(Transaction $transaction): bool
    {
        return $transaction->account->on_budget
            && $transaction->category_id === null
            && ! $transaction->inflow_to_rta
            && $transaction->splits->isEmpty()
            && ! ($transaction->transferPair?->account->on_budget ?? false);
    }
}
