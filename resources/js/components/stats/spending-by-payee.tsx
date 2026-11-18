import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t, tc } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import type { StatsProps } from './stats-data';
import { activeMonths, formatPercent, sum } from './stats-data';

const VISIBLE = 20;

type PayeeRow = {
    key: string;
    label: string;
    amount: number;
    count: number;
};

/**
 * Where the money went over the period, payee by payee: total, monthly
 * average, number of transactions and share of the spending.
 */
export function SpendingByPayee(props: StatsProps) {
    const [query, setQuery] = useState('');
    const [showAll, setShowAll] = useState(false);
    const active = Math.max(activeMonths(props).length, 1);

    const byPayee = new Map<string, PayeeRow>();
    for (const row of props.payees) {
        const key = row.payeeId === null ? 'none' : String(row.payeeId);
        const item = byPayee.get(key) ?? {
            key,
            label: row.payee,
            amount: 0,
            count: 0,
        };
        item.amount += row.amount;
        item.count += row.count;
        byPayee.set(key, item);
    }

    const rows = [...byPayee.values()]
        .filter((row) => row.amount > 0)
        .sort((a, b) => b.amount - a.amount);
    const total = sum(rows.map((row) => row.amount));
    const largest = rows[0]?.amount ?? 0;
    const search = query.trim().toLocaleLowerCase();
    const matching = search
        ? rows.filter((row) => row.label.toLocaleLowerCase().includes(search))
        : rows;
    const visible = showAll || search ? matching : matching.slice(0, VISIBLE);

    if (rows.length === 0) {
        return (
            <p className="rounded-xl border py-16 text-center text-sm text-muted-foreground">
                {t('No spending over the period.')}
            </p>
        );
    }

    return (
        <div className="space-y-4">
            <dl className="grid gap-4 rounded-xl border p-5 text-sm sm:grid-cols-3">
                <div>
                    <dt className="text-muted-foreground">
                        {t('Total spending')}
                    </dt>
                    <dd className="text-lg font-medium tabular-nums">
                        {formatMoney(total)}
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">{t('Payees')}</dt>
                    <dd className="text-lg font-medium tabular-nums">
                        {rows.length}
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">
                        {t('Biggest payee')}
                    </dt>
                    <dd className="truncate text-lg font-medium">
                        {rows[0].label}
                    </dd>
                </div>
            </dl>

            <section className="rounded-xl border">
                <div className="border-b p-3">
                    <Input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={t('Search a payee')}
                        aria-label={t('Search a payee')}
                        className="max-w-xs"
                    />
                </div>
                <div className="hidden justify-between gap-3 border-b px-5 py-3 text-sm text-muted-foreground sm:flex">
                    <span>{t('Payee')}</span>
                    <span className="flex gap-6">
                        <span className="w-32 text-right whitespace-nowrap">
                            {t('Average per month')}
                        </span>
                        <span className="w-32 text-right whitespace-nowrap">
                            {t('Total')}
                        </span>
                    </span>
                </div>
                {matching.length === 0 ? (
                    <p className="px-5 py-6 text-sm text-muted-foreground">
                        {t('No payee matches “:query”.', {
                            query: query.trim(),
                        })}
                    </p>
                ) : (
                    <ul className="divide-y">
                        {visible.map((row) => {
                            const share = (row.amount / total) * 100;

                            return (
                                <li key={row.key} className="px-5 py-3">
                                    <div className="flex items-baseline justify-between gap-3 text-sm">
                                        <span className="min-w-0">
                                            <span className="block truncate">
                                                {row.label}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {tc(
                                                    ':count transaction|:count transactions',
                                                    row.count,
                                                )}
                                            </span>
                                        </span>
                                        <span className="flex shrink-0 gap-6">
                                            <span className="hidden w-32 text-right whitespace-nowrap text-muted-foreground tabular-nums sm:block">
                                                {formatMoney(
                                                    Math.round(
                                                        row.amount / active,
                                                    ),
                                                )}
                                            </span>
                                            <span className="w-32 text-right font-medium whitespace-nowrap tabular-nums">
                                                {formatMoney(row.amount)}
                                            </span>
                                        </span>
                                    </div>
                                    <div className="mt-1.5 flex items-center gap-2">
                                        <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className="fx-grow h-full rounded-full"
                                                style={{
                                                    width: `${Math.max((row.amount / largest) * 100, 1)}%`,
                                                    background:
                                                        'var(--series-1)',
                                                }}
                                            />
                                        </div>
                                        <span className="w-10 text-right text-xs text-muted-foreground tabular-nums">
                                            {formatPercent(share)}
                                        </span>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
                {!search && !showAll && matching.length > VISIBLE && (
                    <div className="border-t p-3 text-center">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setShowAll(true)}
                        >
                            {tc(
                                'Show :count more payee|Show :count more payees',
                                matching.length - VISIBLE,
                            )}
                        </Button>
                    </div>
                )}
            </section>
        </div>
    );
}
