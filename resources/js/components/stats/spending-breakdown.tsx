import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { Cell, Label, Pie, PieChart } from 'recharts';
import { Button } from '@/components/ui/button';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { currentMonth, formatMonth } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import type { Series, StatsProps } from './stats-data';
import {
    chartConfig,
    formatPercent,
    indexSpending,
    OTHER_KEY,
    SLOT_COUNT,
    seriesColor,
    sum,
    UNCATEGORIZED_KEY,
} from './stats-data';

type Item = Series & { amount: number };

/**
 * Where the money went in one month of the period, by category or by group.
 */
export function SpendingBreakdown(props: StatsProps) {
    const [month, setMonth] = useState(props.to);
    const [by, setBy] = useState<'categories' | 'groups'>('categories');
    const { byCategory, byGroup, groupOf } = indexSpending(props);

    // Slots go to the biggest entities of the whole period, so a category keeps
    // its color from one month to the next.
    const entries: Array<{
        key: string;
        label: string;
        months: Record<string, number>;
    }> =
        by === 'categories'
            ? [...byCategory].flatMap(([id, months]) => {
                  const group = groupOf.get(id);
                  const category = group?.categories.find((c) => c.id === id);

                  return category
                      ? [
                            {
                                key: `category-${id}`,
                                label: category.name,
                                months,
                            },
                        ]
                      : [];
              })
            : props.groups
                  .filter((group) => byGroup.has(group.id))
                  .map((group) => ({
                      key: `group-${group.id}`,
                      label: group.name,
                      months: byGroup.get(group.id)!,
                  }));

    const ranked = [...entries].sort(
        (a, b) => sum(Object.values(b.months)) - sum(Object.values(a.months)),
    );
    const items: Item[] = ranked
        .map((entry, rank) => ({
            key: entry.key,
            label: entry.label,
            slot: rank < SLOT_COUNT ? rank : null,
            amount: entry.months[month] ?? 0,
        }))
        .filter((item) => item.amount > 0);

    const uncategorized = props.uncategorized[month] ?? 0;

    if (uncategorized > 0) {
        items.push({
            key: UNCATEGORIZED_KEY,
            label: t('Uncategorized'),
            slot: null,
            amount: uncategorized,
        });
    }

    items.sort((a, b) => b.amount - a.amount);

    const total = sum(items.map((item) => item.amount));
    const others = items.filter((item) => item.slot === null);
    const slices: Item[] = items.filter((item) => item.slot !== null);

    if (others.length > 0) {
        slices.push({
            key: OTHER_KEY,
            label: others.length === 1 ? others[0].label : t('Other'),
            slot: null,
            amount: sum(others.map((item) => item.amount)),
        });
    }

    const config = chartConfig([...slices, ...others]);
    const index = props.months.indexOf(month);
    const days =
        month === currentMonth()
            ? new Date().getDate()
            : new Date(
                  Number(month.slice(0, 4)),
                  Number(month.slice(5)),
                  0,
              ).getDate();
    const top = items.find((item) => item.key !== UNCATEGORIZED_KEY);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <div className="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('Previous month')}
                        disabled={index <= 0}
                        onClick={() => setMonth(props.months[index - 1])}
                    >
                        <ChevronLeft />
                    </Button>
                    <span className="min-w-36 text-center font-medium first-letter:uppercase">
                        {formatMonth(month)}
                    </span>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('Next month')}
                        disabled={index >= props.months.length - 1}
                        onClick={() => setMonth(props.months[index + 1])}
                    >
                        <ChevronRight />
                    </Button>
                </div>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    size="sm"
                    value={by}
                    onValueChange={(value) =>
                        value && setBy(value as 'categories' | 'groups')
                    }
                    className="ml-auto"
                >
                    <ToggleGroupItem value="categories" className="px-3">
                        {t('Categories')}
                    </ToggleGroupItem>
                    <ToggleGroupItem value="groups" className="px-3">
                        {t('Groups')}
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            {total === 0 ? (
                <p className="rounded-xl border py-16 text-center text-sm text-muted-foreground">
                    {t('No spending in :month.', {
                        month: formatMonth(month),
                    })}
                </p>
            ) : (
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_24rem]">
                    <section className="space-y-6 rounded-xl border p-5">
                        <ChartContainer
                            config={config}
                            className="mx-auto aspect-square max-h-96"
                        >
                            <PieChart>
                                <ChartTooltip
                                    content={
                                        <ChartTooltipContent
                                            hideLabel
                                            formatter={(_, __, item) => (
                                                <div className="flex w-full items-center justify-between gap-4">
                                                    <span className="text-muted-foreground">
                                                        {item.payload.label}
                                                    </span>
                                                    <span className="font-medium text-foreground tabular-nums">
                                                        {formatMoney(
                                                            item.payload.amount,
                                                        )}{' '}
                                                        (
                                                        {formatPercent(
                                                            (item.payload
                                                                .amount /
                                                                total) *
                                                                100,
                                                        )}
                                                        )
                                                    </span>
                                                </div>
                                            )}
                                        />
                                    }
                                />
                                <Pie
                                    data={slices}
                                    dataKey="amount"
                                    nameKey="key"
                                    innerRadius="62%"
                                    outerRadius="92%"
                                    paddingAngle={1}
                                    cornerRadius={4}
                                    stroke="var(--background)"
                                    strokeWidth={2}
                                >
                                    {slices.map((slice) => (
                                        <Cell
                                            key={slice.key}
                                            fill={`var(--color-${slice.key})`}
                                        />
                                    ))}
                                    <Label
                                        content={({ viewBox }) =>
                                            viewBox && 'cx' in viewBox ? (
                                                <text
                                                    x={viewBox.cx}
                                                    y={viewBox.cy}
                                                    textAnchor="middle"
                                                    dominantBaseline="middle"
                                                >
                                                    <tspan
                                                        x={viewBox.cx}
                                                        dy="-0.6em"
                                                        className="fill-muted-foreground text-sm"
                                                    >
                                                        {t('Spending')}
                                                    </tspan>
                                                    <tspan
                                                        x={viewBox.cx}
                                                        dy="1.5em"
                                                        className="fill-foreground text-2xl font-semibold"
                                                    >
                                                        {formatMoney(total)}
                                                    </tspan>
                                                </text>
                                            ) : null
                                        }
                                    />
                                </Pie>
                            </PieChart>
                        </ChartContainer>

                        <dl className="grid gap-4 border-t pt-5 text-sm sm:grid-cols-3">
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('Month total')}
                                </dt>
                                <dd className="text-lg font-medium tabular-nums">
                                    {formatMoney(total)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('Average per day')}
                                </dt>
                                <dd className="text-lg font-medium tabular-nums">
                                    {formatMoney(Math.round(total / days))}
                                </dd>
                            </div>
                            {top && (
                                <div>
                                    <dt className="text-muted-foreground">
                                        {t('Biggest expense')}
                                    </dt>
                                    <dd className="truncate text-lg font-medium">
                                        {top.label}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    </section>

                    <section className="rounded-xl border">
                        <div className="flex justify-between border-b px-5 py-3 text-sm text-muted-foreground">
                            <span>
                                {by === 'categories'
                                    ? t('Category')
                                    : t('Group')}
                            </span>
                            <span>{t('Spending')}</span>
                        </div>
                        <ul className="divide-y">
                            {items.map((item) => {
                                const share = (item.amount / total) * 100;

                                return (
                                    <li key={item.key} className="px-5 py-3">
                                        <div className="flex items-baseline justify-between gap-3 text-sm">
                                            <span className="truncate">
                                                {item.label}
                                            </span>
                                            <span className="font-medium tabular-nums">
                                                {formatMoney(item.amount)}
                                            </span>
                                        </div>
                                        <div className="mt-1.5 flex items-center gap-2">
                                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="fx-grow h-full rounded-full"
                                                    style={{
                                                        width: `${Math.max(share, 1)}%`,
                                                        background: seriesColor(
                                                            item.slot,
                                                        ),
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
                    </section>
                </div>
            )}
        </div>
    );
}
