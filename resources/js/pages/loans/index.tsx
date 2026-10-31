import { Head, Link, usePage } from '@inertiajs/react';
import { Landmark, Plus } from 'lucide-react';
import Heading from '@/components/heading';
import type {
    LoanAccount,
    LoanCategoryGroup,
    LoanSummary,
} from '@/components/loans/loan-data';
import { formatLongDate, timeLeft } from '@/components/loans/loan-data';
import { LoanFormDialog } from '@/components/loans/loan-form-dialog';
import { formatPercent } from '@/components/stats/stats-data';
import { useIsViewer } from '@/hooks/use-is-viewer';
import { Button } from '@/components/ui/button';
import { t, tc } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { index, show } from '@/routes/loans';

type Props = {
    loans: LoanSummary[];
    accounts: LoanAccount[];
    categoryGroups: LoanCategoryGroup[];
};

/**
 * The household's loans, each with what is left to repay.
 */
export default function LoansIndex({ loans, accounts, categoryGroups }: Props) {
    const { currentTeam } = usePage().props;
    const isViewer = useIsViewer();

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={t('Loans')} />
            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6 2xl:p-8">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title={t('Loans')}
                        description={t(
                            'Outstanding principal, interest and end of each loan.',
                        )}
                    />
                    {!isViewer && (
                        <LoanFormDialog
                            accounts={accounts}
                            categoryGroups={categoryGroups}
                        >
                            <Button size="sm">
                                <Plus />
                                {t('Add a loan')}
                            </Button>
                        </LoanFormDialog>
                    )}
                </div>

                {loans.length === 0 && (
                    <div className="rounded-xl border border-dashed p-10 text-center">
                        <Landmark className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 font-medium">{t('No loan yet.')}</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t(
                                'Add one from your bank’s “Loan details” to track its repayment.',
                            )}
                        </p>
                    </div>
                )}

                <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    {loans.map((loan) => {
                        const share =
                            loan.principal > 0
                                ? (loan.repaid / loan.principal) * 100
                                : 0;

                        return (
                            <Link
                                key={loan.id}
                                href={show({
                                    current_team: currentTeam.slug,
                                    loan: loan.id,
                                })}
                                className="space-y-4 rounded-xl border border-sidebar-border/70 p-5 transition-colors hover:bg-muted/40 dark:border-sidebar-border"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h2 className="truncate font-semibold">
                                            {loan.name}
                                        </h2>
                                        <p className="text-xs text-muted-foreground">
                                            {tc(
                                                ':count line|:count lines',
                                                loan.parts.length,
                                            )}
                                            {loan.account &&
                                                ` · ${loan.account.name}`}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-xs text-muted-foreground">
                                            {t('Outstanding principal')}
                                        </p>
                                        <p className="text-xl font-semibold tabular-nums">
                                            {formatMoney(loan.remaining)}
                                        </p>
                                    </div>
                                </div>
                                <div className="space-y-1.5">
                                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="fx-grow h-full rounded-full bg-emerald-500"
                                            style={{ width: `${share}%` }}
                                        />
                                    </div>
                                    <p className="flex justify-between text-xs text-muted-foreground tabular-nums">
                                        <span>
                                            {t(':percent repaid', {
                                                percent: formatPercent(share),
                                            })}
                                        </span>
                                        <span>
                                            {t(':amount / month', {
                                                amount: formatMoney(
                                                    loan.payment,
                                                ),
                                            })}
                                        </span>
                                    </p>
                                </div>
                                {loan.endDate && (
                                    <p className="text-sm text-muted-foreground">
                                        {t('Ends on :date, in :duration', {
                                            date: formatLongDate(loan.endDate),
                                            duration: timeLeft(loan.endDate),
                                        })}
                                    </p>
                                )}
                            </Link>
                        );
                    })}
                </div>
            </div>
        </>
    );
}

LoansIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: t('Loans'),
            href: props.currentTeam ? index(props.currentTeam.slug) : '/',
        },
    ],
});
