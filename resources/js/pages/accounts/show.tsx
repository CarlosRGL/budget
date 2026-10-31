import { Head, router, usePage } from '@inertiajs/react';
import { Archive, ArchiveRestore, Pencil, Scale } from 'lucide-react';
import AdjustBalanceDialog from '@/components/adjust-balance-dialog';
import EditAccountDialog from '@/components/edit-account-dialog';
import { Money } from '@/components/money';
import { TransactionRegister } from '@/components/transactions/transaction-register';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { close, reopen, show } from '@/routes/accounts';
import type { AccountDetails, Register, TransactionPickers } from '@/types';

type Props = {
    account: AccountDetails;
    register: Register;
    pickers?: TransactionPickers;
};

export default function AccountShow({ account, register, pickers }: Props) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const parameters = { current_team: currentTeam.slug, account: account.id };

    return (
        <>
            <Head title={account.name} />
            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <header className="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <div className="flex flex-wrap items-start justify-between gap-4 p-4 sm:p-5">
                        <div className="space-y-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-semibold">
                                    {account.name}
                                </h1>
                                <Badge variant="secondary">
                                    {account.typeLabel}
                                </Badge>
                                <Badge variant="outline">
                                    {account.onBudget
                                        ? t('On budget')
                                        : t('Off budget')}
                                </Badge>
                                {account.closed && (
                                    <Badge variant="destructive">
                                        {t('Closed')}
                                    </Badge>
                                )}
                            </div>
                            {account.note && (
                                <p className="max-w-prose text-sm whitespace-pre-line text-muted-foreground">
                                    {account.note}
                                </p>
                            )}
                        </div>

                        <div className="sm:text-right">
                            <p className="text-sm text-muted-foreground">
                                {t('Balance')}
                            </p>
                            <Money
                                cents={account.balance}
                                className="text-2xl font-semibold"
                            />
                            {account.bankBalance !== null && (
                                <p className="text-xs text-muted-foreground">
                                    {t('Bank:')}{' '}
                                    <Money cents={account.bankBalance} />
                                    {account.bankBalance !==
                                        account.balance && (
                                        <span className="ml-1 text-amber-700 dark:text-amber-400">
                                            ({t('difference')}{' '}
                                            <Money
                                                cents={
                                                    account.balance -
                                                    account.bankBalance
                                                }
                                            />
                                            )
                                        </span>
                                    )}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2 border-t border-sidebar-border/70 px-4 py-2.5 sm:px-5 dark:border-sidebar-border">
                        <EditAccountDialog account={account}>
                            <Button variant="ghost" size="sm">
                                <Pencil />
                                {t('Edit')}
                            </Button>
                        </EditAccountDialog>

                        {!account.closed && (
                            <AdjustBalanceDialog account={account}>
                                <Button variant="ghost" size="sm">
                                    <Scale />
                                    {account.onBudget
                                        ? t('Reconcile')
                                        : t('Update the balance')}
                                </Button>
                            </AdjustBalanceDialog>
                        )}

                        {account.closed ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        reopen(parameters).url,
                                        {},
                                        {
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                <ArchiveRestore />
                                {t('Reopen the account')}
                            </Button>
                        ) : (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        close(parameters).url,
                                        {},
                                        {
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                <Archive />
                                {t('Close the account')}
                            </Button>
                        )}
                    </div>
                </header>

                <TransactionRegister
                    register={register}
                    pickers={pickers}
                    accountId={account.id}
                    url={show(parameters).url}
                />
            </div>
        </>
    );
}

AccountShow.layout = (props: {
    currentTeam?: { slug: string } | null;
    account: AccountDetails;
}) => ({
    breadcrumbs: [
        {
            title: props.account.name,
            href: props.currentTeam
                ? show({
                      current_team: props.currentTeam.slug,
                      account: props.account.id,
                  })
                : '/',
        },
    ],
});
