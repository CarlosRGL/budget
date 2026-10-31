import type { ChartConfig } from '@/components/ui/chart';
import { intlLocale } from '@/lib/i18n';

export type StatsGroup = {
    id: number;
    name: string;
    categories: Array<{ id: number; name: string }>;
};

export type StatsProps = {
    from: string;
    to: string;
    months: string[];
    accounts: Array<{ id: number; name: string; closed: boolean }>;
    selectedAccounts: number[] | null;
    groups: StatsGroup[];
    spending: Array<{ categoryId: number; month: string; amount: number }>;
    uncategorized: Record<string, number>;
    income: Array<{ payee: string; month: string; amount: number }>;
};

/**
 * Categorical slots in fixed order (`--series-N` in app.css, light and dark
 * steps): color follows the entity, never its rank in a given month. Past the
 * last slot, entities fold into "Other".
 */
export const SLOT_COUNT = 7;

export function seriesColor(slot: number | null): string {
    return slot === null ? 'var(--series-other)' : `var(--series-${slot + 1})`;
}

export const UNCATEGORIZED_KEY = 'uncategorized';
export const OTHER_KEY = 'other';

/**
 * One series of a chart: a key usable as a CSS variable name, a label and a
 * color slot (null for "Other" and "Uncategorized").
 */
export type Series = { key: string; label: string; slot: number | null };

export function chartConfig(series: Series[]): ChartConfig {
    return Object.fromEntries(
        series.map((item) => [
            item.key,
            {
                label: item.label,
                color: seriesColor(item.slot),
            },
        ]),
    );
}

/**
 * Spending indexed for the views: by category and month, by group and month,
 * plus the group of each category.
 */
export function indexSpending(props: StatsProps) {
    const byCategory = new Map<number, Record<string, number>>();

    for (const row of props.spending) {
        const months = byCategory.get(row.categoryId) ?? {};
        months[row.month] = (months[row.month] ?? 0) + row.amount;
        byCategory.set(row.categoryId, months);
    }

    const groupOf = new Map<number, StatsGroup>();
    for (const group of props.groups) {
        for (const category of group.categories) {
            groupOf.set(category.id, group);
        }
    }

    const byGroup = new Map<number, Record<string, number>>();
    for (const [categoryId, months] of byCategory) {
        const group = groupOf.get(categoryId);

        if (!group) {
            continue;
        }

        const totals = byGroup.get(group.id) ?? {};
        for (const [month, amount] of Object.entries(months)) {
            totals[month] = (totals[month] ?? 0) + amount;
        }
        byGroup.set(group.id, totals);
    }

    const monthTotal = (month: string) =>
        [...byCategory.values()].reduce(
            (sum, months) => sum + (months[month] ?? 0),
            0,
        ) + (props.uncategorized[month] ?? 0);

    return { byCategory, byGroup, groupOf, monthTotal };
}

export function sum(values: Array<number | undefined>): number {
    return values.reduce<number>((total, value) => total + (value ?? 0), 0);
}

/**
 * Months of the period from the first one with any figure: months before the
 * household started using the app would drag every average down.
 */
export function activeMonths(props: StatsProps): string[] {
    const used = new Set([
        ...props.spending.map((row) => row.month),
        ...props.income.map((row) => row.month),
        ...Object.keys(props.uncategorized),
    ]);
    const first = props.months.findIndex((month) => used.has(month));

    return first === -1 ? [] : props.months.slice(first);
}

const percentFormat = new Intl.NumberFormat(intlLocale(), {
    style: 'percent',
    maximumFractionDigits: 0,
});

/**
 * A share out of 100 as a whole percentage: `42 %`, `42%`.
 */
export function formatPercent(share: number): string {
    return percentFormat.format(share / 100);
}
