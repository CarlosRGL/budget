import { Head, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import CreateAccountDialog from '@/components/create-account-dialog';
import Heading from '@/components/heading';
import { Money } from '@/components/money';
import { sectionTitles } from '@/components/nav-accounts';
import { TransactionRegister } from '@/components/transactions/transaction-register';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index } from '@/routes/accounts';
import type { Register, TransactionPickers } from '@/types';

function StatCard({
    label,
    cents,
    hint,
    strong = false,
}: {
    label: string;
    cents: number;
    hint: string;
    strong?: boolean;
}) {
    return (
        <div
            className={cn(
                'min-w-0 rounded-xl border border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border',
                strong && 'bg-muted/50',
            )}
        >
            <p className="truncate text-xs text-muted-foreground">{label}</p>
            <Money
                cents={cents}
                className="block text-lg font-semibold sm:text-xl"
            />
            <p className="truncate text-xs text-muted-foreground">{hint}</p>
        </div>
    );
}

type Props = {
    register: Register;
    pickers?: TransactionPickers;
};

export default function AccountsIndex({ register, pickers }: Props) {
    const { currentTeam, sidebarAccounts } = usePage().props;

    if (!currentTeam || !sidebarAccounts) {
        return null;
    }

    const { sections } = sidebarAccounts;

    return (
        <>
            <Head title={t('All accounts')} />
            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title={t('All accounts')}
                        description={t(
                            'The transactions of all your accounts.',
                        )}
                    />
                    <CreateAccountDialog>
                        <Button variant="outline" size="sm">
                            <Plus />
                            {t('Add account')}
                        </Button>
                    </CreateAccountDialog>
                </div>

                {sections.length === 0 && (
                    <p className="text-muted-foreground">
                        {t(
                            'No accounts yet: start by adding your checking account.',
                        )}
                    </p>
                )}

                {sections.length > 0 && (
                    <>
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <StatCard
                                label={t('Net worth')}
                                cents={sections.reduce(
                                    (sum, section) => sum + section.total,
                                    0,
                                )}
                                hint={t('All open accounts')}
                                strong
                            />
                            {sections.map((section) => (
                                <StatCard
                                    key={section.key}
                                    label={sectionTitles[section.key]}
                                    cents={section.total}
                                    hint={section.accounts
                                        .map((account) => account.name)
                                        .join(' · ')}
                                />
                            ))}
                        </div>

                        <TransactionRegister
                            register={register}
                            pickers={pickers}
                            accountId={null}
                            url={index(currentTeam.slug).url}
                        />
                    </>
                )}
            </div>
        </>
    );
}

AccountsIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: t('All accounts'),
            href: props.currentTeam ? index(props.currentTeam.slug) : '/',
        },
    ],
});
