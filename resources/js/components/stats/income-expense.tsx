import { ChevronDown, ChevronRight } from 'lucide-react';
import { Fragment, useState } from 'react';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { StatsProps } from './stats-data';
import { activeMonths, indexSpending, sum } from './stats-data';
import { intlLocale, t } from '@/lib/i18n';

const shortMonth = new Intl.DateTimeFormat(intlLocale(), {
    month: 'short',
    year: 'numeric',
});

type Row = { label: string; months: Record<string, number> };

/**
 * Income by payee against spending by group and category, month by month,
 * with the average and the total of the period.
 */
export function IncomeExpense(props: StatsProps) {
    const { byCategory, byGroup } = indexSpending(props);
    const [collapsed, setCollapsed] = useState<number[]>([]);
    const months = props.months;
    const active = Math.max(activeMonths(props).length, 1);

    const incomeRows: Row[] = [];
    for (const row of props.income) {
        let payee = incomeRows.find((item) => item.label === row.payee);

        if (!payee) {
            payee = { label: row.payee, months: {} };
            incomeRows.push(payee);
        }

        payee.months[row.month] = (payee.months[row.month] ?? 0) + row.amount;
    }
    incomeRows.sort(
        (a, b) => sum(Object.values(b.months)) - sum(Object.values(a.months)),
    );

    const totalOf = (rows: Row[], month: string) =>
        sum(rows.map((row) => row.months[month]));
    const incomeTotal: Row = {
        label: t('Total income'),
        months: Object.fromEntries(
            months.map((month) => [month, totalOf(incomeRows, month)]),
        ),
    };

    const groups = props.groups
        .filter((group) => byGroup.has(group.id))
        .map((group) => ({
            id: group.id,
            total: { label: group.name, months: byGroup.get(group.id)! },
            categories: group.categories
                .filter((category) => byCategory.has(category.id))
                .map((category) => ({
                    label: category.name,
                    months: byCategory.get(category.id)!,
                })),
        }));
    const uncategorized: Row = {
        label: t('Uncategorized'),
        months: props.uncategorized,
    };
    const hasUncategorized = months.some(
        (month) => (props.uncategorized[month] ?? 0) !== 0,
    );
    const expenseTotal: Row = {
        label: t('Total spending'),
        months: Object.fromEntries(
            months.map((month) => [
                month,
                sum(groups.map((group) => group.total.months[month])) +
                    (props.uncategorized[month] ?? 0),
            ]),
        ),
    };
    const net: Row = {
        label: t('Balance (income − spending)'),
        months: Object.fromEntries(
            months.map((month) => [
                month,
                (incomeTotal.months[month] ?? 0) -
                    (expenseTotal.months[month] ?? 0),
            ]),
        ),
    };

    const toggle = (id: number) =>
        setCollapsed((current) =>
            current.includes(id)
                ? current.filter((item) => item !== id)
                : [...current, id],
        );

    const cells = (row: Row, options: { signed?: boolean } = {}) => {
        const values = months.map((month) => row.months[month] ?? 0);
        const total = sum(values);

        return [...values, Math.round(total / active), total].map(
            (value, index) => (
                <td
                    key={index}
                    className={cn(
                        'px-4 py-2.5 text-right whitespace-nowrap tabular-nums',
                        index >= months.length && 'bg-muted/30',
                        value === 0 && 'text-muted-foreground/60',
                        options.signed &&
                            value > 0 &&
                            'text-emerald-600 dark:text-emerald-400',
                        options.signed &&
                            value < 0 &&
                            'text-red-600 dark:text-red-400',
                    )}
                >
                    {formatMoney(value)}
                </td>
            ),
        );
    };

    const section = (title: string, className: string) => (
        <tr className="border-b">
            <th
                colSpan={months.length + 3}
                className={cn(
                    'px-4 pt-5 pb-2 text-left text-base font-semibold',
                    className,
                )}
            >
                {title}
            </th>
        </tr>
    );

    return (
        <section className="overflow-x-auto rounded-xl border max-sm:-mx-4 max-sm:rounded-none max-sm:border-x-0">
            <table className="w-full text-sm">
                <thead className="text-xs tracking-wide text-muted-foreground uppercase">
                    <tr className="border-b">
                        <th className="sticky left-0 bg-background px-4 py-3 text-left font-medium" />
                        {months.map((month) => (
                            <th
                                key={month}
                                className="px-4 py-3 text-right font-medium whitespace-nowrap"
                            >
                                {shortMonth.format(
                                    new Date(`${month}-15T12:00:00`),
                                )}
                            </th>
                        ))}
                        <th className="bg-muted/30 px-4 py-3 text-right font-medium">
                            {t('Average')}
                        </th>
                        <th className="bg-muted/30 px-4 py-3 text-right font-medium">
                            {t('Total')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {section(
                        t('Income'),
                        'text-emerald-600 dark:text-emerald-400',
                    )}
                    {incomeRows.length === 0 && (
                        <tr className="border-b">
                            <td
                                colSpan={months.length + 3}
                                className="px-4 py-2.5 text-muted-foreground"
                            >
                                {t(
                                    'No income over the period (what goes into “Ready to Assign”).',
                                )}
                            </td>
                        </tr>
                    )}
                    {incomeRows.map((row) => (
                        <tr key={row.label} className="border-b">
                            <td className="sticky left-0 max-w-72 truncate bg-background px-4 py-2.5 pl-8">
                                {row.label}
                            </td>
                            {cells(row)}
                        </tr>
                    ))}
                    <tr className="border-b font-medium">
                        <td className="sticky left-0 bg-background px-4 py-2.5">
                            {incomeTotal.label}
                        </td>
                        {cells(incomeTotal)}
                    </tr>

                    {section(t('Spending'), 'text-red-600 dark:text-red-400')}
                    {hasUncategorized && (
                        <tr className="border-b">
                            <td className="sticky left-0 bg-background px-4 py-2.5 pl-8 text-amber-700 dark:text-amber-400">
                                {uncategorized.label}
                            </td>
                            {cells(uncategorized)}
                        </tr>
                    )}
                    {groups.map((group) => {
                        const open = !collapsed.includes(group.id);

                        return (
                            <Fragment key={group.id}>
                                <tr className="border-b bg-muted font-medium">
                                    <td className="sticky left-0 bg-muted px-4 py-2.5">
                                        <button
                                            type="button"
                                            onClick={() => toggle(group.id)}
                                            aria-expanded={open}
                                            className="flex items-center gap-1.5"
                                        >
                                            {open ? (
                                                <ChevronDown className="size-4" />
                                            ) : (
                                                <ChevronRight className="size-4" />
                                            )}
                                            {group.total.label}
                                        </button>
                                    </td>
                                    {cells(group.total)}
                                </tr>
                                {open &&
                                    group.categories.map((category) => (
                                        <tr
                                            key={category.label}
                                            className="border-b"
                                        >
                                            <td className="sticky left-0 max-w-72 truncate bg-background px-4 py-2.5 pl-10">
                                                {category.label}
                                            </td>
                                            {cells(category)}
                                        </tr>
                                    ))}
                            </Fragment>
                        );
                    })}
                    <tr className="border-b font-medium">
                        <td className="sticky left-0 bg-background px-4 py-2.5">
                            {expenseTotal.label}
                        </td>
                        {cells(expenseTotal)}
                    </tr>

                    <tr className="font-semibold">
                        <td className="sticky left-0 bg-background px-4 py-3">
                            {net.label}
                        </td>
                        {cells(net, { signed: true })}
                    </tr>
                </tbody>
            </table>
        </section>
    );
}
