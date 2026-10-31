import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { LoanSummary } from '@/components/loans/loan-data';
import { Money } from '@/components/money';
import { sectionTitles } from '@/components/nav-accounts';
import { MonthNav } from '@/components/simple/month-nav';
import type { SimpleTransaction } from '@/components/simple/transaction-list';
import { TransactionList } from '@/components/simple/transaction-list';
import { formatPercent } from '@/components/stats/stats-data';
import { t, tc } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import { show as loanShow } from '@/routes/loans';
import { overview, spending } from '@/routes/simple';

type SimpleCategory = {
    id: number;
    name: string;
    planned: number;
    spent: number;
    left: number;
};

type SimpleGroup = {
    id: number;
    name: string;
    categories: SimpleCategory[];
};

type Props = {
    month: string;
    previousMonth: string;
    nextMonth: string;
    groups: SimpleGroup[];
    previousSpent: number;
    recent: SimpleTransaction[];
    loans: LoanSummary[];
};

/**
 * Share of the money of a category already spent, and the matching colour:
 * green while there is room, amber near the end, red once over.
 */
function usage(spent: number, left: number) {
    const total = spent + Math.max(left, 0);
    const ratio = total > 0 ? Math.min(Math.max(spent, 0) / total, 1) : 0;
    const tone =
        left < 0
            ? 'bg-red-500'
            : ratio >= 0.9 && left > 0
              ? 'bg-amber-500'
              : 'bg-emerald-500';

    return { ratio: left < 0 ? 1 : ratio, tone };
}

function Bar({
    spent,
    left,
    className,
}: {
    spent: number;
    left: number;
    className?: string;
}) {
    const { ratio, tone } = usage(spent, left);

    return (
        <div
            className={cn(
                'h-1.5 overflow-hidden rounded-full bg-muted',
                className,
            )}
        >
            <div
                className={cn('fx-grow h-full rounded-full', tone)}
                style={{ width: `${ratio * 100}%` }}
            />
        </div>
    );
}

function Left({ cents }: { cents: number }) {
    return cents < 0 ? (
        <span className="font-medium text-red-600 tabular-nums dark:text-red-400">
            {t('over by :amount', { amount: formatMoney(-cents) })}
        </span>
    ) : (
        <span className="font-medium tabular-nums">
            {t(':amount left', { amount: formatMoney(cents) })}
        </span>
    );
}

/**
 * The month at a glance, for members who follow the budget without running
 * it: what was planned for each category, spent and still left.
 */
export default function SimpleOverview({
    month,
    previousMonth,
    nextMonth,
    groups,
    previousSpent,
    recent,
    loans,
}: Props) {
    const { currentTeam, sidebarAccounts } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const team = currentTeam.slug;
    const categories = groups.flatMap((group) => group.categories);
    const spent = categories.reduce((sum, c) => sum + c.spent, 0);
    const overspent = categories.filter((category) => category.left < 0);

    return (
        <>
            <Head title={t('This month')} />
            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <MonthNav
                    month={month}
                    previousHref={
                        overview({ current_team: team, month: previousMonth })
                            .url
                    }
                    nextHref={
                        overview({ current_team: team, month: nextMonth }).url
                    }
                />

                <section className="space-y-3 rounded-2xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div>
                        <p className="text-sm text-muted-foreground">
                            {t('Spent this month')}
                        </p>
                        <p className="text-3xl font-semibold tabular-nums">
                            {formatMoney(spent)}
                        </p>
                        {previousSpent > 0 && (
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    spent <= previousSpent
                                        ? ':amount less than the previous month (:previous)'
                                        : ':amount more than the previous month (:previous)',
                                    {
                                        amount: formatMoney(
                                            Math.abs(spent - previousSpent),
                                        ),
                                        previous: formatMoney(previousSpent),
                                    },
                                )}
                            </p>
                        )}
                    </div>
                    {overspent.length > 0 ? (
                        <div className="space-y-1.5 rounded-lg bg-red-500/10 px-3 py-2 text-sm ring-1 ring-red-500/30 ring-inset">
                            <p className="font-medium text-red-700 dark:text-red-300">
                                {tc(
                                    ':count category overspent|:count categories overspent',
                                    overspent.length,
                                )}
                            </p>
                            <ul className="space-y-0.5">
                                {overspent.map((category) => (
                                    <li
                                        key={category.id}
                                        className="flex justify-between gap-3 tabular-nums"
                                    >
                                        <span className="truncate">
                                            {category.name}
                                        </span>
                                        <span className="text-red-700 dark:text-red-300">
                                            +{formatMoney(-category.left)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : (
                        <p className="text-sm text-emerald-700 dark:text-emerald-400">
                            {t('No category overspent this month.')}
                        </p>
                    )}
                </section>

                {sidebarAccounts && sidebarAccounts.sections.length > 0 && (
                    <section className="space-y-1">
                        <h2 className="px-1 font-semibold">
                            {t('Accounts today')}
                        </h2>
                        <div className="divide-y divide-sidebar-border/70 overflow-hidden rounded-xl border border-sidebar-border/70 max-sm:-mx-4 max-sm:rounded-none max-sm:border-x-0 dark:divide-sidebar-border dark:border-sidebar-border">
                            {sidebarAccounts.sections.map((section) => (
                                <div key={section.key} className="text-sm">
                                    <div className="flex items-baseline justify-between gap-3 bg-muted/40 px-4 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        <span>
                                            {sectionTitles[section.key]}
                                        </span>
                                        <Money cents={section.total} />
                                    </div>
                                    <ul className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                        {section.accounts.map((account) => (
                                            <li
                                                key={account.id}
                                                className="flex items-baseline justify-between gap-3 px-4 py-2.5"
                                            >
                                                <span className="min-w-0 truncate">
                                                    {account.name}
                                                </span>
                                                <Money
                                                    cents={account.balance}
                                                    className="font-medium"
                                                />
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    </section>
                )}

                {groups.map((group) => {
                    const groupLeft = group.categories.reduce(
                        (sum, c) => sum + c.left,
                        0,
                    );

                    return (
                        <section key={group.id} className="space-y-1">
                            <div className="flex items-baseline justify-between gap-3 px-1">
                                <h2 className="font-semibold">{group.name}</h2>
                                <span className="text-sm text-muted-foreground">
                                    <Left cents={groupLeft} />
                                </span>
                            </div>
                            <ul className="divide-y divide-sidebar-border/70 overflow-hidden rounded-xl border border-sidebar-border/70 max-sm:-mx-4 max-sm:rounded-none max-sm:border-x-0 dark:divide-sidebar-border dark:border-sidebar-border">
                                {group.categories.map((category) => (
                                    <li
                                        key={category.id}
                                        className="space-y-2 px-4 py-3 text-sm"
                                    >
                                        <div className="flex items-baseline justify-between gap-3">
                                            <span className="min-w-0 truncate font-medium">
                                                {category.name}
                                            </span>
                                            <Left cents={category.left} />
                                        </div>
                                        <Bar
                                            spent={category.spent}
                                            left={category.left}
                                        />
                                        <p className="flex justify-between gap-3 text-xs text-muted-foreground tabular-nums">
                                            <span>
                                                {t('Spent :amount', {
                                                    amount: formatMoney(
                                                        category.spent,
                                                    ),
                                                })}
                                            </span>
                                            <span>
                                                {t('Planned :amount', {
                                                    amount: formatMoney(
                                                        category.planned,
                                                    ),
                                                })}
                                            </span>
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    );
                })}

                <section className="space-y-1">
                    <div className="flex items-baseline justify-between gap-3 px-1">
                        <h2 className="font-semibold">
                            {t('Latest spending')}
                        </h2>
                        <Link
                            href={spending({ current_team: team, month }).url}
                            className="flex items-center text-sm text-muted-foreground hover:text-foreground"
                        >
                            {t('See all')}
                            <ChevronRight className="size-4" />
                        </Link>
                    </div>
                    <TransactionList transactions={recent} />
                </section>

                {loans.length > 0 && (
                    <section className="space-y-1">
                        <h2 className="px-1 font-semibold">{t('Loans')}</h2>
                        <div className="grid gap-3 md:grid-cols-2">
                            {loans.map((loan) => {
                                const share =
                                    loan.principal > 0
                                        ? (loan.repaid / loan.principal) * 100
                                        : 0;

                                return (
                                    <Link
                                        key={loan.id}
                                        href={
                                            loanShow({
                                                current_team: team,
                                                loan: loan.id,
                                            }).url
                                        }
                                        className="space-y-2 rounded-xl border border-sidebar-border/70 p-4 text-sm transition-colors hover:bg-muted/40 dark:border-sidebar-border"
                                    >
                                        <div className="flex items-baseline justify-between gap-3">
                                            <span className="font-medium">
                                                {loan.name}
                                            </span>
                                            <span className="text-muted-foreground tabular-nums">
                                                {t(':percent repaid', {
                                                    percent:
                                                        formatPercent(share),
                                                })}
                                            </span>
                                        </div>
                                        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className="fx-grow h-full rounded-full bg-emerald-500"
                                                style={{ width: `${share}%` }}
                                            />
                                        </div>
                                        <p className="text-xs text-muted-foreground tabular-nums">
                                            {t(
                                                ':remaining left to repay · :payment per month',
                                                {
                                                    remaining: formatMoney(
                                                        loan.remaining,
                                                    ),
                                                    payment: formatMoney(
                                                        loan.payment,
                                                    ),
                                                },
                                            )}
                                        </p>
                                    </Link>
                                );
                            })}
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}

SimpleOverview.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: t('This month'),
            href: props.currentTeam
                ? overview({ current_team: props.currentTeam.slug })
                : '/',
        },
    ],
});
