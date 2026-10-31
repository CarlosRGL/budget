import { Money } from '@/components/money';
import { cn } from '@/lib/utils';
import { intlLocale, t } from '@/lib/i18n';

export type SimpleTransaction = {
    id: number;
    date: string;
    payee: string | null;
    categories: string[];
    income: boolean;
    account: string | null;
    memo: string | null;
    amount: number;
};

const dayFormat = new Intl.DateTimeFormat(intlLocale(), {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
});

/**
 * Transactions as a plain list: who, which category, how much.
 */
export function TransactionList({
    transactions,
    className,
}: {
    transactions: SimpleTransaction[];
    className?: string;
}) {
    return (
        <ul
            className={cn(
                'divide-y divide-sidebar-border/70 overflow-hidden rounded-xl border border-sidebar-border/70 max-sm:-mx-4 max-sm:rounded-none max-sm:border-x-0 dark:divide-sidebar-border dark:border-sidebar-border',
                className,
            )}
        >
            {transactions.map((transaction) => (
                <li
                    key={transaction.id}
                    className="flex items-center gap-3 px-4 py-3 text-sm"
                >
                    <div className="min-w-0 flex-1">
                        <p className="truncate font-medium">
                            {transaction.payee ?? t('No payee')}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {dayFormat.format(
                                new Date(`${transaction.date}T12:00:00`),
                            )}
                            {' · '}
                            {transaction.income
                                ? t('Income received')
                                : transaction.categories.length > 0
                                  ? transaction.categories.join(', ')
                                  : t('Not categorized yet')}
                            {transaction.memo && ` · ${transaction.memo}`}
                        </p>
                    </div>
                    <Money
                        cents={transaction.amount}
                        plain
                        className={cn(
                            'font-medium',
                            transaction.amount > 0 &&
                                'text-emerald-600 dark:text-emerald-400',
                        )}
                    />
                </li>
            ))}
            {transactions.length === 0 && (
                <li className="px-4 py-8 text-center text-sm text-muted-foreground">
                    {t('No transaction this month.')}
                </li>
            )}
        </ul>
    );
}
