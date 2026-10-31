<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Support\Collection;

/**
 * The household's accounts as shown in the sidebar: open accounts split into
 * "Comptes" (budget), "Prêts" (loans) and "Suivi" (other off-budget accounts),
 * each with its total, and the closed ones apart.
 */
class SidebarAccounts
{
    /**
     * Build the sidebar data for the current household, in a single query.
     *
     * @return array{
     *     sections: array<int, array{key: string, total: int, accounts: array<int, array<string, mixed>>}>,
     *     closed: array<int, array<string, mixed>>,
     *     types: array<int, array{value: string, label: string, onBudgetByDefault: bool}>,
     * }
     */
    public static function forCurrentTeam(): array
    {
        $accounts = Account::query()
            ->withSum('transactions as balance', 'amount')
            ->orderBy('sort')
            ->orderBy('name')
            ->get();

        [$closed, $open] = $accounts->partition(fn (Account $account) => $account->isClosed());

        $bySection = $open->groupBy(fn (Account $account) => static::sectionOf($account));

        return [
            'sections' => collect(['budget', 'loans', 'tracking'])
                ->filter(fn (string $key) => $bySection->has($key))
                ->map(fn (string $key) => static::section($key, $bySection->get($key, collect())))
                ->values()
                ->all(),
            'closed' => $closed->map(static::present(...))->values()->all(),
            'types' => collect(AccountType::cases())
                ->map(fn (AccountType $type) => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'onBudgetByDefault' => $type->isOnBudgetByDefault(),
                ])
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array{key: string, total: int, accounts: array<int, array<string, mixed>>}
     */
    protected static function section(string $key, Collection $accounts): array
    {
        return [
            'key' => $key,
            'total' => (int) $accounts->sum('balance'),
            'accounts' => $accounts->map(static::present(...))->values()->all(),
        ];
    }

    /**
     * The sidebar section an open account belongs to.
     */
    public static function sectionOf(Account $account): string
    {
        return match (true) {
            $account->on_budget => 'budget',
            $account->type === AccountType::Loan => 'loans',
            default => 'tracking',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected static function present(Account $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'onBudget' => $account->on_budget,
            'balance' => (int) $account->getAttribute('balance'),
        ];
    }
}
