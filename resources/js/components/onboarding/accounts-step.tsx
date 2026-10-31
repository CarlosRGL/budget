import { Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, Landmark, Plus, Wallet } from 'lucide-react';
import CreateAccountDialog from '@/components/create-account-dialog';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import {
    index as bankConnections,
    setup as bankSetup,
} from '@/routes/bank-connections';

/**
 * Add the accounts by hand (they stay listed here) and/or connect a bank.
 */
export function AccountsStep({
    bankingConfigured,
}: {
    bankingConfigured: boolean;
}) {
    const { currentTeam, sidebarAccounts } = usePage().props;
    const accounts = (sidebarAccounts?.sections ?? []).flatMap(
        (section) => section.accounts,
    );
    const team = { current_team: currentTeam?.slug ?? '' };

    return (
        <div className="space-y-6">
            <header className="space-y-2 text-center">
                <h1 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                    {t('Where is your money?')}
                </h1>
                <p className="mx-auto max-w-md text-pretty text-muted-foreground">
                    {t(
                        'Add your accounts with their current balance: the money on budget accounts becomes your Ready to Assign.',
                    )}
                </p>
            </header>

            <div className="grid gap-3 sm:grid-cols-2">
                <section className="flex flex-col rounded-2xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border">
                    <div className="mb-3 flex size-9 items-center justify-center rounded-xl bg-sky-500/15 text-sky-700 dark:text-sky-300">
                        <Wallet className="size-4.5" />
                    </div>
                    <p className="font-medium">{t('Add an account by hand')}</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t(
                            'Current account, savings, cash… You enter the transactions yourself.',
                        )}
                    </p>

                    {accounts.length > 0 && (
                        <ul className="mt-4 divide-y rounded-xl border">
                            {accounts.map((account) => (
                                <li
                                    key={account.id}
                                    className="fx-swap flex items-center gap-3 px-3 py-2 text-sm"
                                >
                                    <span className="truncate">
                                        {account.name}
                                    </span>
                                    <span
                                        className={cn(
                                            'ml-auto font-medium tabular-nums',
                                            account.balance < 0 &&
                                                'text-red-600 dark:text-red-400',
                                        )}
                                    >
                                        {formatMoney(account.balance)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}

                    <div className="mt-auto pt-4">
                        <CreateAccountDialog stay>
                            <Button
                                variant={
                                    accounts.length > 0 ? 'outline' : 'default'
                                }
                                className="w-full"
                            >
                                <Plus />
                                {accounts.length > 0
                                    ? t('Add another account')
                                    : t('Add account')}
                            </Button>
                        </CreateAccountDialog>
                    </div>
                </section>

                <section className="flex flex-col rounded-2xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border">
                    <div className="mb-3 flex size-9 items-center justify-center rounded-xl bg-violet-500/15 text-violet-700 dark:text-violet-300">
                        <Landmark className="size-4.5" />
                    </div>
                    <p className="font-medium">{t('Connect your bank')}</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t(
                            'Transactions arrive on their own every day, ready to be categorized.',
                        )}
                    </p>

                    {!bankingConfigured && (
                        <p className="mt-4 rounded-xl bg-amber-500/10 px-3 py-2.5 text-sm text-amber-800 ring-1 ring-amber-500/25 ring-inset dark:text-amber-200">
                            {t(
                                'Bank connections are not set up on this server yet: it needs an EnableBanking application.',
                            )}
                        </p>
                    )}

                    <div className="mt-auto pt-4">
                        <Button variant="outline" className="w-full" asChild>
                            <Link
                                href={
                                    bankingConfigured
                                        ? bankConnections(team)
                                        : bankSetup(team)
                                }
                            >
                                {bankingConfigured
                                    ? t('Connect a bank')
                                    : t('How to set up bank connections')}
                                <ArrowUpRight />
                            </Link>
                        </Button>
                    </div>
                </section>
            </div>

            <p className="text-center text-sm text-muted-foreground">
                {t('No rush: you can add or connect accounts later too.')}
            </p>
        </div>
    );
}
