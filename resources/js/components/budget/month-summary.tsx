import { Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronRight,
    CircleCheck,
    Inbox,
    Target,
    Wand2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Money } from '@/components/money';
import { Button } from '@/components/ui/button';
import { formatMonth } from '@/lib/dates';
import { intlLocale, t, tc } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import { index as accountsIndex } from '@/routes/accounts';
import type { BudgetCategory, BudgetGroup } from '@/types/budget';
import type { BudgetActions } from './budget-actions';

const percent = new Intl.NumberFormat(intlLocale(), {
    style: 'percent',
    maximumFractionDigits: 0,
});

type Props = {
    month: string;
    groups: BudgetGroup[];
    readyToAssign: number;
    uncategorizedActivity: number;
    actions: BudgetActions;
    onSelect: (categoryId: number) => void;
};

function Task({
    icon,
    tone,
    title,
    children,
}: {
    icon: ReactNode;
    tone: 'red' | 'amber' | 'emerald' | 'muted';
    title: ReactNode;
    children?: ReactNode;
}) {
    return (
        <li className="flex gap-3 py-3">
            <span
                className={cn(
                    'mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md ring-1 ring-inset [&_svg]:size-3.5',
                    tone === 'red' &&
                        'bg-red-500/10 text-red-600 ring-red-500/30 dark:text-red-400',
                    tone === 'amber' &&
                        'bg-amber-500/10 text-amber-700 ring-amber-500/30 dark:text-amber-400',
                    tone === 'emerald' &&
                        'bg-emerald-500/10 text-emerald-700 ring-emerald-500/30 dark:text-emerald-400',
                    tone === 'muted' &&
                        'bg-muted text-muted-foreground ring-border',
                )}
            >
                {icon}
            </span>
            <div className="min-w-0 flex-1 space-y-2">
                <p className="leading-snug font-medium">{title}</p>
                {children}
            </div>
        </li>
    );
}

/**
 * What the month needs when no category is selected: money to assign,
 * overspending to cover, targets to fund, transactions to review, and the
 * month's totals.
 */
export function MonthSummary({
    month,
    groups,
    readyToAssign,
    uncategorizedActivity,
    actions,
    onSelect,
}: Props) {
    const { currentTeam, inboxCount } = usePage().props;
    const categories = groups
        .filter((group) => !group.hidden)
        .flatMap((group) =>
            group.categories.filter((category) => !category.hidden),
        );
    const total = (pick: (category: BudgetCategory) => number) =>
        categories.reduce((sum, category) => sum + pick(category), 0);

    const overspent = categories
        .filter((category) => category.available < 0)
        .sort((a, b) => a.available - b.available);
    const underfunded = categories.filter(
        (category) => category.target?.underfunded,
    );
    const toFund = underfunded.reduce(
        (sum, category) => sum + (category.target?.toGo ?? 0),
        0,
    );
    const targeted = categories.filter(
        (category) => category.target && !category.target.snoozed,
    );
    const needed = targeted.reduce(
        (sum, category) => sum + (category.target?.needed ?? 0),
        0,
    );
    const funded = targeted.reduce(
        (sum, category) =>
            sum +
            Math.min(
                category.target?.funded ?? 0,
                category.target?.needed ?? 0,
            ),
        0,
    );

    const allClear =
        readyToAssign === 0 &&
        overspent.length === 0 &&
        underfunded.length === 0 &&
        inboxCount === 0 &&
        uncategorizedActivity === 0;

    return (
        <aside className="space-y-5 rounded-xl border border-sidebar-border/70 p-4 text-sm dark:border-sidebar-border">
            <div>
                <p className="text-xs text-muted-foreground">
                    {t('Month summary')}
                </p>
                <h2 className="text-lg font-semibold first-letter:uppercase">
                    {formatMonth(month)}
                </h2>
            </div>

            <section>
                <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('To do')}
                </h3>
                <ul className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    {readyToAssign < 0 && (
                        <Task
                            icon={<AlertTriangle />}
                            tone="red"
                            title={t(':amount over-assigned', {
                                amount: formatMoney(-readyToAssign),
                            })}
                        >
                            <p className="text-muted-foreground">
                                {t(
                                    'Take this money back from envelopes that do not need it.',
                                )}
                            </p>
                        </Task>
                    )}

                    {readyToAssign > 0 && (
                        <Task
                            icon={<Wand2 />}
                            tone="emerald"
                            title={t(':amount to assign', {
                                amount: formatMoney(readyToAssign),
                            })}
                        >
                            {underfunded.length > 0 ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="h-7"
                                    onClick={() =>
                                        actions.autoAssign('underfunded')
                                    }
                                >
                                    {t('Fund targets (:amount)', {
                                        amount: formatMoney(
                                            Math.min(toFund, readyToAssign),
                                        ),
                                    })}
                                </Button>
                            ) : (
                                <p className="text-muted-foreground">
                                    {t(
                                        'All targets are funded: set the rest aside.',
                                    )}
                                </p>
                            )}
                        </Task>
                    )}

                    {overspent.length > 0 && (
                        <Task
                            icon={<AlertTriangle />}
                            tone="red"
                            title={tc(
                                ':count overspent envelope|:count overspent envelopes',
                                overspent.length,
                            )}
                        >
                            <ul className="-mx-2">
                                {overspent.map((category) => (
                                    <li key={category.id}>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                onSelect(category.id)
                                            }
                                            className="flex w-full items-center gap-2 rounded-md px-2 py-1 text-left hover:bg-muted"
                                        >
                                            <span className="min-w-0 flex-1 truncate">
                                                {category.name}
                                            </span>
                                            <Money cents={category.available} />
                                            <ChevronRight className="size-3.5 text-muted-foreground" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </Task>
                    )}

                    {underfunded.length > 0 && readyToAssign <= 0 && (
                        <Task
                            icon={<Target />}
                            tone="amber"
                            title={tc(
                                ':count target to fund|:count targets to fund',
                                underfunded.length,
                            )}
                        >
                            <p className="text-muted-foreground">
                                {t(':amount short this month.', {
                                    amount: formatMoney(toFund),
                                })}
                            </p>
                        </Task>
                    )}

                    {(inboxCount > 0 || uncategorizedActivity !== 0) &&
                        currentTeam && (
                            <Task
                                icon={<Inbox />}
                                tone="amber"
                                title={
                                    inboxCount > 0
                                        ? tc(
                                              ':count transaction to review|:count transactions to review',
                                              inboxCount,
                                          )
                                        : t('Uncategorized transactions')
                                }
                            >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="h-7"
                                    asChild
                                >
                                    <Link
                                        href={`${accountsIndex(currentTeam.slug).url}?filter=inbox`}
                                    >
                                        {t('Open')}
                                    </Link>
                                </Button>
                            </Task>
                        )}

                    {allClear && (
                        <Task
                            icon={<CircleCheck />}
                            tone="emerald"
                            title={t('All in order')}
                        >
                            <p className="text-muted-foreground">
                                {t('Every euro has an envelope.')}
                            </p>
                        </Task>
                    )}
                </ul>
            </section>

            {needed > 0 && (
                <section className="space-y-2">
                    <div className="flex items-baseline justify-between">
                        <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {t('Targets')}
                        </h3>
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {percent.format(funded / needed)}
                        </span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            className="fx-grow h-full rounded-full bg-emerald-500"
                            style={{
                                width: `${Math.min((funded / needed) * 100, 100)}%`,
                            }}
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t(':funded funded of :needed needed this month', {
                            funded: formatMoney(funded),
                            needed: formatMoney(needed),
                        })}
                    </p>
                </section>
            )}

            <section className="space-y-1.5">
                <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('This month')}
                </h3>
                <dl className="space-y-1.5">
                    {(
                        [
                            [
                                t('Left over from last month'),
                                total((category) => category.carryover),
                            ],
                            [
                                t('Assigned'),
                                total((category) => category.assigned),
                            ],
                            [
                                t('Activity'),
                                total((category) => category.activity) +
                                    uncategorizedActivity,
                            ],
                        ] as const
                    ).map(([label, cents]) => (
                        <div key={label} className="flex justify-between">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd>
                                <Money cents={cents} plain />
                            </dd>
                        </div>
                    ))}
                    <div className="flex justify-between border-t pt-1.5 font-medium">
                        <dt>{t('Available')}</dt>
                        <dd>
                            <Money
                                cents={total((category) => category.available)}
                            />
                        </dd>
                    </div>
                </dl>
            </section>
        </aside>
    );
}
