import { AlertTriangle, Check, Clock, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { formatMonth } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { LoanPayment } from './loan-data';
import { formatLongDate } from './loan-data';
import { intlLocale, t, tc } from '@/lib/i18n';

const shortDate = new Intl.DateTimeFormat(intlLocale(), {
    day: 'numeric',
    month: 'short',
});

const statuses: Record<
    LoanPayment['status'],
    { label: string; icon: ReactNode; className: string }
> = {
    paid: {
        label: t('Paid'),
        icon: <Check />,
        className:
            'bg-emerald-500/10 text-emerald-700 ring-emerald-500/30 dark:text-emerald-400',
    },
    different: {
        label: t('Different amount'),
        icon: <AlertTriangle />,
        className:
            'bg-amber-500/10 text-amber-700 ring-amber-500/30 dark:text-amber-400',
    },
    missing: {
        label: t('Missing'),
        icon: <X />,
        className:
            'bg-red-500/10 text-red-600 ring-red-500/30 dark:text-red-400',
    },
    upcoming: {
        label: t('Upcoming'),
        icon: <Clock />,
        className: 'bg-muted text-muted-foreground ring-border',
    },
};

const VISIBLE = 6;

/**
 * The monthly payments found in the loan's category, checked against the
 * schedule: paid, a different amount, missing or still to come.
 */
export function LoanPayments({
    payments,
    categoryName,
    accountName,
}: {
    payments: LoanPayment[];
    categoryName: string;
    accountName: string | null;
}) {
    const [showAll, setShowAll] = useState(false);
    const rows = showAll ? payments : payments.slice(0, VISIBLE);

    return (
        <section className="rounded-xl border border-sidebar-border/70 max-sm:-mx-4 max-sm:rounded-none max-sm:border-x-0 dark:border-sidebar-border">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-sidebar-border/70 px-4 py-3 sm:px-5 dark:border-sidebar-border">
                <h2 className="font-semibold">{t('Monthly payments')}</h2>
                <p className="text-xs text-muted-foreground">
                    {t('Category “:category”', { category: categoryName })}
                    {accountName &&
                        ` · ${t('the repaid principal is recorded automatically on “:account”', { account: accountName })}`}
                </p>
            </div>

            {payments.length === 0 ? (
                <p className="px-4 py-6 text-sm text-muted-foreground sm:px-5">
                    {t('No instalment since your first transactions.')}
                </p>
            ) : (
                <ul className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    {rows.map((payment) => {
                        const status = statuses[payment.status];

                        return (
                            <li
                                key={payment.month}
                                className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm sm:px-5"
                            >
                                <div className="min-w-32 flex-1">
                                    <p className="font-medium first-letter:uppercase">
                                        {formatMonth(payment.month)}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {payment.transactions.length > 0
                                            ? payment.transactions
                                                  .map((transaction) =>
                                                      [
                                                          shortDate.format(
                                                              new Date(
                                                                  `${transaction.date}T12:00:00`,
                                                              ),
                                                          ),
                                                          transaction.payee,
                                                          transaction.account,
                                                      ]
                                                          .filter(Boolean)
                                                          .join(' · '),
                                                  )
                                                  .join(' ; ')
                                            : t('Due on :date', {
                                                  date: formatLongDate(
                                                      payment.dueDate,
                                                  ),
                                              })}
                                    </p>
                                </div>
                                <div className="text-right tabular-nums">
                                    <p className="font-medium">
                                        {payment.paid !== 0
                                            ? formatMoney(payment.paid)
                                            : '—'}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {t('of :amount', {
                                            amount: formatMoney(
                                                payment.expected,
                                            ),
                                        })}
                                    </p>
                                </div>
                                <span
                                    className={cn(
                                        'inline-flex w-40 shrink-0 items-center justify-center gap-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset max-sm:w-auto [&_svg]:size-3.5',
                                        status.className,
                                    )}
                                >
                                    {status.icon}
                                    {status.label}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}

            {payments.length > VISIBLE && (
                <div className="border-t border-sidebar-border/70 px-2 py-1.5 dark:border-sidebar-border">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setShowAll((value) => !value)}
                    >
                        {showAll
                            ? t('Show less')
                            : tc('Show all :count months', payments.length)}
                    </Button>
                </div>
            )}
        </section>
    );
}
