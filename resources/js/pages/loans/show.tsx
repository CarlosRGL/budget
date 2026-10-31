import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Pencil, RefreshCw, Trash2 } from 'lucide-react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';
import type {
    LoanAccount,
    LoanCategoryGroup,
    LoanMonth,
    LoanPayment,
    LoanSummary,
} from '@/components/loans/loan-data';
import {
    formatLongDate,
    formatRate,
    timeLeft,
} from '@/components/loans/loan-data';
import { LoanFormDialog } from '@/components/loans/loan-form-dialog';
import { LoanPayments } from '@/components/loans/loan-payments';
import { useIsViewer } from '@/hooks/use-is-viewer';
import { formatPercent, seriesColor } from '@/components/stats/stats-data';
import { Button } from '@/components/ui/button';
import type { ChartConfig } from '@/components/ui/chart';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import { currentMonth, formatMonth } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import { destroy, index, show, syncAccount } from '@/routes/loans';
import { intlLocale, t, tc } from '@/lib/i18n';

type Props = {
    loan: LoanSummary;
    months: LoanMonth[];
    payments: LoanPayment[];
    accounts: LoanAccount[];
    categoryGroups: LoanCategoryGroup[];
};

const compactEuros = new Intl.NumberFormat(intlLocale(), {
    style: 'currency',
    currency: 'EUR',
    notation: 'compact',
    maximumFractionDigits: 0,
});

function Stat({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint?: string;
}) {
    return (
        <div className="min-w-0 rounded-xl border border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
            <p className="truncate text-xs text-muted-foreground">{label}</p>
            <p className="text-base font-semibold tabular-nums sm:text-xl">
                {value}
            </p>
            {hint && (
                <p className="truncate text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}

/**
 * A translated sentence with some of its placeholders shown in bold.
 */
function withStrong(text: string, values: Record<string, string>) {
    return text.split(/(:\w+)/).map((piece, i) => {
        const name = piece.slice(1);

        return piece.startsWith(':') && name in values ? (
            <strong key={i} className="tabular-nums">
                {values[name]}
            </strong>
        ) : (
            piece
        );
    });
}

function moneyTooltip(config: ChartConfig) {
    return (
        <ChartTooltipContent
            labelFormatter={(_, payload) => {
                const key = String(payload?.[0]?.payload?.key ?? '');

                return key.length === 4 ? key : formatMonth(key);
            }}
            formatter={(value, name, item) => (
                <div className="flex w-full items-center justify-between gap-4">
                    <span className="flex items-center gap-2 text-muted-foreground">
                        <span
                            className="size-2.5 rounded-[2px]"
                            style={{ background: item.color }}
                        />
                        {config[String(name)]?.label ?? name}
                    </span>
                    <span className="font-medium text-foreground tabular-nums">
                        {formatMoney(Math.round(Number(value) * 100))}
                    </span>
                </div>
            )}
        />
    );
}

/**
 * One loan: where it stands, how the capital goes down, and the share of
 * interest year after year.
 */
export default function LoanShow({
    loan,
    months,
    payments,
    accounts,
    categoryGroups,
}: Props) {
    const { currentTeam } = usePage().props;
    const isViewer = useIsViewer();
    const today = currentMonth();

    if (!currentTeam) {
        return null;
    }

    const parameters = { current_team: currentTeam.slug, loan: loan.id };
    const share = loan.principal > 0 ? (loan.repaid / loan.principal) * 100 : 0;
    const accountGap = loan.account ? loan.account.balance + loan.remaining : 0;

    // Capital still owed, line by line, month after month.
    const partKeys = loan.parts.map((_, i) => `part-${i}`);
    const balanceConfig: ChartConfig = Object.fromEntries(
        loan.parts.map((part, i) => [
            partKeys[i],
            { label: part.label, color: seriesColor(i) },
        ]),
    );
    const balanceData = months.map((row) => ({
        key: row.month,
        ...Object.fromEntries(
            row.balances.map((balance, i) => [partKeys[i], balance / 100]),
        ),
    }));
    const years = [...new Set(months.map((row) => row.month.slice(0, 4)))];
    const step = years.length > 20 ? 5 : years.length > 10 ? 2 : 1;
    const ticks = months
        .map((row) => row.month)
        .filter(
            (month) =>
                month.endsWith('-01') && Number(month.slice(0, 4)) % step === 0,
        );

    // Capital and interest paid each year.
    const yearly = years.map((year) => {
        const rows = months.filter((row) => row.month.startsWith(year));
        const last = rows[rows.length - 1];

        return {
            key: year,
            principal: rows.reduce((sum, row) => sum + row.principal, 0),
            interest: rows.reduce((sum, row) => sum + row.interest, 0),
            balance: last.balances.reduce((sum, value) => sum + value, 0),
        };
    });
    const hasInterest = loan.totalInterest > 0;
    const yearlyConfig: ChartConfig = {
        principal: { label: t('Principal repaid'), color: seriesColor(0) },
        interest: { label: t('Interest'), color: seriesColor(2) },
    };
    const currentYear = today.slice(0, 4);

    const remove = () => {
        if (window.confirm(t('Delete “:name”?', { name: loan.name }))) {
            router.delete(destroy(parameters).url);
        }
    };

    return (
        <>
            <Head title={loan.name} />
            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6 2xl:gap-6 2xl:p-8">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">{loan.name}</h1>
                        <p className="text-sm text-muted-foreground">
                            {t(':amount borrowed', {
                                amount: formatMoney(loan.principal),
                            })}
                            {loan.endDate &&
                                ` · ${t('ends on :date', { date: formatLongDate(loan.endDate) })}`}
                        </p>
                    </div>
                    <div className={cn('flex gap-1', isViewer && 'hidden')}>
                        <LoanFormDialog
                            loan={loan}
                            accounts={accounts}
                            categoryGroups={categoryGroups}
                        >
                            <Button variant="ghost" size="sm">
                                <Pencil />
                                {t('Edit')}
                            </Button>
                        </LoanFormDialog>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground hover:text-red-600 dark:hover:text-red-400"
                            onClick={remove}
                        >
                            <Trash2 />
                            {t('Delete')}
                        </Button>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat
                        label={t('Outstanding principal')}
                        value={formatMoney(loan.remaining)}
                        hint={t(':percent repaid', {
                            percent: formatPercent(share),
                        })}
                    />
                    <Stat
                        label={t('Monthly payment')}
                        value={formatMoney(loan.payment)}
                        hint={
                            loan.nextPaymentDate
                                ? t('Next on :date', {
                                      date: formatLongDate(
                                          loan.nextPaymentDate,
                                      ),
                                  })
                                : t('Finished')
                        }
                    />
                    <Stat
                        label={t('Remaining interest')}
                        value={formatMoney(
                            loan.totalInterest - loan.paidInterest,
                        )}
                        hint={t('Total cost: :amount', {
                            amount: formatMoney(loan.totalInterest),
                        })}
                    />
                    <Stat
                        label={t('Loan end')}
                        value={loan.endDate ? timeLeft(loan.endDate) : '—'}
                        hint={
                            loan.endDate
                                ? formatLongDate(loan.endDate)
                                : undefined
                        }
                    />
                </div>

                <div className="space-y-1.5">
                    <div className="h-2.5 overflow-hidden rounded-full bg-muted">
                        <div
                            className="fx-grow h-full rounded-full bg-emerald-500"
                            style={{ width: `${share}%` }}
                        />
                    </div>
                    <p className="flex justify-between text-xs text-muted-foreground tabular-nums">
                        <span>
                            {t(':amount repaid', {
                                amount: formatMoney(loan.repaid),
                            })}
                        </span>
                        <span>
                            {t(':amount remaining', {
                                amount: formatMoney(loan.remaining),
                            })}
                        </span>
                    </p>
                </div>

                {!isViewer && loan.account && accountGap !== 0 && (
                    <div className="flex flex-col gap-3 rounded-xl bg-amber-500/10 px-4 py-3 text-sm ring-1 ring-amber-500/30 ring-inset sm:flex-row sm:items-center">
                        <AlertTriangle className="hidden size-4 shrink-0 text-amber-700 sm:block dark:text-amber-400" />
                        <p className="min-w-0 flex-1">
                            {withStrong(
                                t(
                                    'The account “:account” shows :balance while the outstanding principal is :remaining.',
                                    { account: loan.account.name },
                                ),
                                {
                                    balance: formatMoney(loan.account.balance),
                                    remaining: formatMoney(loan.remaining),
                                },
                            )}
                        </p>
                        <Button
                            size="sm"
                            variant="outline"
                            className="self-start sm:self-auto"
                            onClick={() =>
                                router.post(
                                    syncAccount(parameters).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <RefreshCw />
                            {t('Update the account')}
                        </Button>
                    </div>
                )}

                {loan.category ? (
                    <LoanPayments
                        payments={payments}
                        categoryName={loan.category.name}
                        accountName={loan.account?.name ?? null}
                    />
                ) : isViewer ? null : (
                    <div className="flex flex-col gap-3 rounded-xl border border-dashed px-4 py-3 text-sm sm:flex-row sm:items-center">
                        <p className="min-w-0 flex-1 text-muted-foreground">
                            {t(
                                'Link this loan to the category where you file the direct debit to check each monthly payment and give it a target.',
                            )}
                        </p>
                        <LoanFormDialog
                            loan={loan}
                            accounts={accounts}
                            categoryGroups={categoryGroups}
                        >
                            <Button
                                size="sm"
                                variant="outline"
                                className="self-start sm:self-auto"
                            >
                                {t('Choose the category')}
                            </Button>
                        </LoanFormDialog>
                    </div>
                )}

                <div className="grid gap-4 xl:grid-cols-2 2xl:gap-6">
                    <section className="rounded-xl border border-sidebar-border/70 p-4 sm:p-5 dark:border-sidebar-border">
                        <h2 className="font-semibold">
                            {t('Outstanding principal')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'After each instalment, until the end of the loan.',
                            )}
                        </p>
                        <ChartContainer
                            config={balanceConfig}
                            className="mt-4 aspect-auto h-64 w-full sm:h-72 2xl:h-96"
                        >
                            <AreaChart data={balanceData}>
                                <CartesianGrid vertical={false} />
                                <XAxis
                                    dataKey="key"
                                    ticks={ticks}
                                    tickLine={false}
                                    axisLine={false}
                                    tickFormatter={(month: string) =>
                                        month.slice(0, 4)
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
                                    content={moneyTooltip(balanceConfig)}
                                />
                                {loan.parts.length > 1 && (
                                    <ChartLegend
                                        content={<ChartLegendContent />}
                                    />
                                )}
                                {partKeys.map((key) => (
                                    <Area
                                        key={key}
                                        dataKey={key}
                                        stackId="balance"
                                        type="monotone"
                                        fill={`var(--color-${key})`}
                                        fillOpacity={0.35}
                                        stroke={`var(--color-${key})`}
                                        strokeWidth={2}
                                        isAnimationActive={false}
                                    />
                                ))}
                                <ReferenceLine
                                    x={today}
                                    stroke="var(--foreground)"
                                    strokeDasharray="4 4"
                                    label={{
                                        value: t('Today'),
                                        position: 'insideTopRight',
                                        fill: 'var(--muted-foreground)',
                                        fontSize: 12,
                                    }}
                                />
                            </AreaChart>
                        </ChartContainer>
                    </section>

                    <section className="rounded-xl border border-sidebar-border/70 p-4 sm:p-5 dark:border-sidebar-border">
                        <h2 className="font-semibold">
                            {t('Repaid each year')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {hasInterest
                                ? t(
                                      'The share of interest falls as the principal goes down.',
                                  )
                                : t('This loan costs no interest.')}
                        </p>
                        <ChartContainer
                            config={yearlyConfig}
                            className="mt-4 aspect-auto h-64 w-full sm:h-72 2xl:h-96"
                        >
                            <BarChart
                                data={yearly.map((row) => ({
                                    key: row.key,
                                    principal: row.principal / 100,
                                    interest: row.interest / 100,
                                }))}
                                barCategoryGap="20%"
                            >
                                <CartesianGrid vertical={false} />
                                <XAxis
                                    dataKey="key"
                                    tickLine={false}
                                    axisLine={false}
                                    interval={step - 1}
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
                                    content={moneyTooltip(yearlyConfig)}
                                />
                                <ChartLegend content={<ChartLegendContent />} />
                                <Bar
                                    dataKey="principal"
                                    stackId="year"
                                    fill="var(--color-principal)"
                                    isAnimationActive={false}
                                    stroke="var(--background)"
                                    strokeWidth={1}
                                    radius={hasInterest ? 0 : [4, 4, 0, 0]}
                                />
                                {hasInterest && (
                                    <Bar
                                        dataKey="interest"
                                        stackId="year"
                                        fill="var(--color-interest)"
                                        isAnimationActive={false}
                                        stroke="var(--background)"
                                        strokeWidth={1}
                                        radius={[4, 4, 0, 0]}
                                    />
                                )}
                            </BarChart>
                        </ChartContainer>
                    </section>
                </div>

                <div className="grid gap-4 2xl:gap-6">
                    <section className="overflow-x-auto rounded-xl border border-sidebar-border/70 max-sm:-mx-4 max-sm:rounded-none max-sm:border-x-0 dark:border-sidebar-border">
                        <table className="w-full text-sm whitespace-nowrap">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        {t('Line')}
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium max-sm:hidden">
                                        {t('Borrowed')}
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium">
                                        {t('Rate')}
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium max-sm:hidden">
                                        {t('Term')}
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium max-sm:hidden">
                                        {t('Monthly payment')}
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium">
                                        {t('Outstanding')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                {loan.parts.map((part, i) => (
                                    <tr key={part.id}>
                                        <td className="px-4 py-2.5">
                                            <span className="flex items-center gap-2">
                                                <span
                                                    className="size-2.5 shrink-0 rounded-[2px]"
                                                    style={{
                                                        background:
                                                            seriesColor(i),
                                                    }}
                                                />
                                                {part.label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2.5 text-right tabular-nums max-sm:hidden">
                                            {formatMoney(part.principal)}
                                        </td>
                                        <td className="px-4 py-2.5 text-right tabular-nums">
                                            {formatRate(part.rateMilli)}
                                        </td>
                                        <td className="px-4 py-2.5 text-right tabular-nums max-sm:hidden">
                                            {tc(
                                                ':count month|:count months',
                                                part.months,
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5 text-right tabular-nums max-sm:hidden">
                                            {formatMoney(
                                                part.payment ??
                                                    part.computedPayment,
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5 text-right font-medium tabular-nums">
                                            {formatMoney(part.remaining)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>

                    <section className="overflow-x-auto rounded-xl border border-sidebar-border/70 max-sm:-mx-4 max-sm:rounded-none max-sm:border-x-0 dark:border-sidebar-border">
                        <table className="w-full text-sm whitespace-nowrap">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        {t('Year')}
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium max-sm:hidden">
                                        {t('Principal repaid')}
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium">
                                        {t('Interest')}
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium">
                                        {t('Outstanding at year end')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                {yearly.map((row) => (
                                    <tr
                                        key={row.key}
                                        className={cn(
                                            row.key < currentYear &&
                                                'text-muted-foreground',
                                            row.key === currentYear &&
                                                'bg-emerald-500/10 font-medium',
                                        )}
                                    >
                                        <td className="px-4 py-2 tabular-nums">
                                            {row.key}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums max-sm:hidden">
                                            {formatMoney(row.principal)}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {formatMoney(row.interest)}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {formatMoney(row.balance)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                </div>
            </div>
        </>
    );
}

LoanShow.layout = (props: {
    currentTeam?: { slug: string } | null;
    loan: LoanSummary;
}) => ({
    breadcrumbs: [
        {
            title: t('Loans'),
            href: props.currentTeam ? index(props.currentTeam.slug) : '/',
        },
        {
            title: props.loan.name,
            href: props.currentTeam
                ? show({
                      current_team: props.currentTeam.slug,
                      loan: props.loan.id,
                  })
                : '/',
        },
    ],
});
