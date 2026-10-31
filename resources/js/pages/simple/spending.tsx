import { Head, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { Fragment, useState } from 'react';
import { MonthNav } from '@/components/simple/month-nav';
import type { SimpleTransaction } from '@/components/simple/transaction-list';
import { TransactionList } from '@/components/simple/transaction-list';
import { intlLocale, t, tc } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { spending } from '@/routes/simple';

type Props = {
    month: string;
    previousMonth: string;
    nextMonth: string;
    transactions: SimpleTransaction[];
};

const ALL = '';

/**
 * Every transaction of the month, to search by shop or category.
 */
export default function SimpleSpending({
    month,
    previousMonth,
    nextMonth,
    transactions,
}: Props) {
    const { currentTeam } = usePage().props;
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState(ALL);

    if (!currentTeam) {
        return null;
    }

    const team = currentTeam.slug;
    const categories = [
        ...new Set(
            transactions.flatMap((transaction) => transaction.categories),
        ),
    ].sort((a, b) => a.localeCompare(b, intlLocale()));
    const query = search.trim().toLocaleLowerCase(intlLocale());
    const visible = transactions.filter(
        (transaction) =>
            (category === ALL || transaction.categories.includes(category)) &&
            (query === '' ||
                [transaction.payee, transaction.memo, ...transaction.categories]
                    .filter(Boolean)
                    .some((text) =>
                        text!.toLocaleLowerCase(intlLocale()).includes(query),
                    )),
    );
    const spent = -visible
        .filter((transaction) => !transaction.income)
        .reduce((sum, transaction) => sum + transaction.amount, 0);

    return (
        <>
            <Head title={t('Spending')} />
            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <MonthNav
                    month={month}
                    previousHref={
                        spending({ current_team: team, month: previousMonth })
                            .url
                    }
                    nextHref={
                        spending({ current_team: team, month: nextMonth }).url
                    }
                />

                <div className="flex flex-col gap-2 sm:flex-row">
                    <label className="relative flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            type="search"
                            aria-label={t('Search')}
                            placeholder={t('Search for a shop…')}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="h-10 w-full rounded-lg border bg-transparent pr-3 pl-9 text-sm outline-none focus:ring-2 focus:ring-ring/30"
                        />
                    </label>
                    <select
                        aria-label={t('Category')}
                        value={category}
                        onChange={(event) => setCategory(event.target.value)}
                        className="h-10 rounded-lg border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring/30 sm:w-56"
                    >
                        <option value={ALL}>{t('All categories')}</option>
                        {categories.map((name) => (
                            <option key={name} value={name}>
                                {name}
                            </option>
                        ))}
                    </select>
                </div>

                <p className="px-1 text-sm text-muted-foreground">
                    {tc(
                        ':count transaction · :amount spent|:count transactions · :amount spent',
                        visible.length,
                    )
                        .split(':amount')
                        .map((piece, i) =>
                            i === 0 ? (
                                piece
                            ) : (
                                <Fragment key={i}>
                                    <span className="font-medium text-foreground tabular-nums">
                                        {formatMoney(spent)}
                                    </span>
                                    {piece}
                                </Fragment>
                            ),
                        )}
                </p>

                <TransactionList transactions={visible} />
            </div>
        </>
    );
}

SimpleSpending.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: t('Spending'),
            href: props.currentTeam
                ? spending({ current_team: props.currentTeam.slug })
                : '/',
        },
    ],
});
