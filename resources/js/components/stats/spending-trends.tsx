import { ArrowDown, ArrowUp } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import { formatMonth } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import type { Series, StatsProps } from './stats-data';
import {
    activeMonths,
    chartConfig,
    indexSpending,
    OTHER_KEY,
    SLOT_COUNT,
    sum,
    UNCATEGORIZED_KEY,
} from './stats-data';
import { intlLocale, t } from '@/lib/i18n';

const shortMonth = new Intl.DateTimeFormat(intlLocale(), {
    month: 'short',
    year: '2-digit',
});

const compactEuros = new Intl.NumberFormat(intlLocale(), {
    style: 'currency',
    currency: 'EUR',
    notation: 'compact',
    maximumFractionDigits: 1,
});

const gapPercent = new Intl.NumberFormat(intlLocale(), {
    style: 'percent',
    maximumFractionDigits: 1,
});

/**
 * Spending per month, stacked by group, with the monthly average and each
 * month against it.
 */
export function SpendingTrends(props: StatsProps) {
    const { byGroup, monthTotal } = indexSpending(props);

    // Groups keep their budget order (and so their color) whatever the period.
    const series: Series[] = props.groups
        .map((group, index) => ({
            key: `group-${group.id}`,
            label: group.name,
            slot: index < SLOT_COUNT ? index : null,
            id: group.id,
        }))
        .filter((item) => byGroup.has(item.id));
    const folded = series.filter((item) => item.slot === null);
    const shown: Series[] = series.filter((item) => item.slot !== null);

    if (folded.length > 0) {
        shown.push({ key: OTHER_KEY, label: t('Other'), slot: null });
    }

    const hasUncategorized = props.months.some(
        (month) => (props.uncategorized[month] ?? 0) !== 0,
    );

    if (hasUncategorized) {
        shown.push({
            key: UNCATEGORIZED_KEY,
            label: t('Uncategorized'),
            slot: null,
        });
    }

    const data = props.months.map((month) => {
        const row: Record<string, number | string> = { month };

        for (const group of props.groups) {
            const amount = byGroup.get(group.id)?.[month] ?? 0;
            const item = series.find((s) => s.key === `group-${group.id}`);

            if (!item) {
                continue;
            }

            const key = item.slot === null ? OTHER_KEY : item.key;
            row[key] = ((row[key] as number | undefined) ?? 0) + amount / 100;
        }

        if (hasUncategorized) {
            row[UNCATEGORIZED_KEY] = (props.uncategorized[month] ?? 0) / 100;
        }

        // Net refunds can make a month negative: the bars start at zero.
        for (const key of Object.keys(row)) {
            if (key !== 'month' && (row[key] as number) < 0) {
                row[key] = 0;
            }
        }

        return row;
    });

    const active = activeMonths(props);
    const total = sum(props.months.map((month) => monthTotal(month)));
    const average = active.length ? total / active.length : 0;
    const config = chartConfig(shown);

    return (
        <div className="space-y-6">
            <section className="rounded-xl border p-5">
                <div className="flex flex-wrap gap-x-12 gap-y-3">
                    <div>
                        <p className="text-sm text-muted-foreground">
                            {t('Average monthly spending')}
                        </p>
                        <p className="text-3xl font-semibold tabular-nums">
                            {formatMoney(Math.round(average))}
                        </p>
                    </div>
                    <div>
                        <p className="text-sm text-muted-foreground">
                            {t('Total over the period')}
                        </p>
                        <p className="text-xl font-medium tabular-nums">
                            {formatMoney(total)}
                        </p>
                    </div>
                </div>

                {total === 0 ? (
                    <p className="py-16 text-center text-sm text-muted-foreground">
                        {t('No spending over this period.')}
                    </p>
                ) : (
                    <ChartContainer
                        config={config}
                        className="mt-6 aspect-auto h-80 w-full"
                    >
                        <BarChart data={data} barCategoryGap="30%">
                            <CartesianGrid vertical={false} />
                            <XAxis
                                dataKey="month"
                                tickLine={false}
                                axisLine={false}
                                tickFormatter={(month: string) =>
                                    shortMonth.format(
                                        new Date(`${month}-15T12:00:00`),
                                    )
                                }
                            />
                            <YAxis
                                tickLine={false}
                                axisLine={false}
                                width={64}
                                tickFormatter={(value: number) =>
                                    compactEuros.format(value)
                                }
                            />
                            <ChartTooltip
                                content={
                                    <ChartTooltipContent
                                        labelFormatter={(_, payload) =>
                                            formatMonth(
                                                String(
                                                    payload?.[0]?.payload
                                                        ?.month ?? '',
                                                ),
                                            )
                                        }
                                        formatter={(value, name, item) => (
                                            <div className="flex w-full items-center justify-between gap-4">
                                                <span className="flex items-center gap-2 text-muted-foreground">
                                                    <span
                                                        className="size-2.5 rounded-[2px]"
                                                        style={{
                                                            background:
                                                                item.color,
                                                        }}
                                                    />
                                                    {config[String(name)]
                                                        ?.label ?? name}
                                                </span>
                                                <span className="font-medium text-foreground tabular-nums">
                                                    {formatMoney(
                                                        Math.round(
                                                            Number(value) * 100,
                                                        ),
                                                    )}
                                                </span>
                                            </div>
                                        )}
                                    />
                                }
                            />
                            <ChartLegend content={<ChartLegendContent />} />
                            {shown.map((item, index) => (
                                <Bar
                                    key={item.key}
                                    dataKey={item.key}
                                    stackId="spending"
                                    fill={`var(--color-${item.key})`}
                                    stroke="var(--background)"
                                    strokeWidth={2}
                                    radius={
                                        index === shown.length - 1
                                            ? [4, 4, 0, 0]
                                            : 0
                                    }
                                    maxBarSize={72}
                                />
                            ))}
                        </BarChart>
                    </ChartContainer>
                )}
            </section>

            <section className="overflow-hidden rounded-xl border max-sm:-mx-4 max-sm:rounded-none max-sm:border-x-0">
                <table className="w-full text-sm">
                    <thead className="text-muted-foreground">
                        <tr className="border-b">
                            <th className="px-5 py-3 text-left font-medium">
                                {t('Month')}
                            </th>
                            <th className="px-5 py-3 text-right font-medium">
                                {t('Spending')}
                            </th>
                            <th className="px-5 py-3 text-right font-medium">
                                {t('Compared with the average')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {[...props.months].reverse().map((month) => {
                            const spent = monthTotal(month);
                            const gap = spent - average;

                            return (
                                <tr
                                    key={month}
                                    className="border-b last:border-0"
                                >
                                    <td className="px-5 py-3 first-letter:uppercase">
                                        {formatMonth(month)}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums">
                                        {formatMoney(spent)}
                                    </td>
                                    <td className="px-5 py-3 text-right text-muted-foreground tabular-nums">
                                        {average === 0 ||
                                        !active.includes(month) ? (
                                            '—'
                                        ) : (
                                            <span className="inline-flex items-center gap-1">
                                                {gap >= 0 ? (
                                                    <ArrowUp className="size-3.5" />
                                                ) : (
                                                    <ArrowDown className="size-3.5" />
                                                )}
                                                {formatMoney(
                                                    Math.round(Math.abs(gap)),
                                                )}{' '}
                                                (
                                                {gapPercent.format(
                                                    Math.abs(gap / average),
                                                )}
                                                )
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </section>
        </div>
    );
}
