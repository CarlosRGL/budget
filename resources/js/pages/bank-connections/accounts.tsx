import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Money } from '@/components/money';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { t } from '@/lib/i18n';
import { index, saveAccounts } from '@/routes/bank-connections';

type BankAccountRow = {
    id: number;
    name: string;
    isCard: boolean;
    ibanMasked: string | null;
    currency: string | null;
    accountId: number | null;
    bankBalance: number | null;
};

type Props = {
    connection: { id: number; aspspName: string };
    bankAccounts: BankAccountRow[];
    accounts: Array<{ id: number; name: string }>;
    accountTypes: Array<{ value: string; label: string }>;
};

type Mapping = {
    bank_account_id: number;
    action: 'existing' | 'new' | 'ignore';
    account_id: number | null;
    name: string;
    type: string;
};

/**
 * Value of the single select: an existing account id, "new" or "ignore".
 */
function choiceOf(mapping: Mapping): string {
    return mapping.action === 'existing' && mapping.account_id
        ? String(mapping.account_id)
        : mapping.action;
}

export default function MapBankAccounts({
    connection,
    bankAccounts,
    accounts,
    accountTypes,
}: Props) {
    const { currentTeam } = usePage().props;
    // Once some accounts are mapped, the others were left out on purpose.
    const firstMapping = bankAccounts.every(
        (bankAccount) => bankAccount.accountId === null,
    );
    const form = useForm<{ mappings: Mapping[] }>({
        mappings: bankAccounts.map((bankAccount) => ({
            bank_account_id: bankAccount.id,
            action: bankAccount.accountId
                ? 'existing'
                : bankAccount.isCard || !firstMapping
                  ? 'ignore'
                  : 'new',
            account_id: bankAccount.accountId,
            name: bankAccount.name,
            type: /livret|épargne|epargne|savings|ahorro/i.test(
                bankAccount.name,
            )
                ? 'savings'
                : 'checking',
        })),
    });

    if (!currentTeam) {
        return null;
    }

    const setMapping = (index: number, changes: Partial<Mapping>) =>
        form.setData(
            'mappings',
            form.data.mappings.map((mapping, position) =>
                position === index ? { ...mapping, ...changes } : mapping,
            ),
        );

    const choose = (index: number, value: string) =>
        setMapping(
            index,
            value === 'new' || value === 'ignore'
                ? { action: value, account_id: null }
                : { action: 'existing', account_id: Number(value) },
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(
            saveAccounts({
                current_team: currentTeam.slug,
                bank_connection: connection.id,
            }).url,
        );
    };

    const errors = form.errors as Record<string, string>;

    return (
        <>
            <Head title={t(':bank accounts', { bank: connection.aspspName })} />
            <form
                onSubmit={submit}
                className="flex flex-1 flex-col gap-6 p-4 md:p-6"
            >
                <Heading
                    title={t(':bank accounts', { bank: connection.aspspName })}
                    description={t(
                        'Match each bank account with an existing account, or create it. Only transactions after the account’s start date are imported.',
                    )}
                />

                <ul className="divide-y divide-sidebar-border/70 overflow-hidden rounded-xl border border-sidebar-border/70 max-sm:-mx-4 max-sm:rounded-none max-sm:border-x-0 dark:divide-sidebar-border dark:border-sidebar-border">
                    {bankAccounts.map((bankAccount, index) => {
                        const mapping = form.data.mappings[index];

                        return (
                            <li key={bankAccount.id} className="space-y-3 p-4">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <p className="font-medium">
                                        {bankAccount.name}
                                        {bankAccount.ibanMasked &&
                                            !bankAccount.name.includes(
                                                bankAccount.ibanMasked,
                                            ) && (
                                                <span className="ml-2 text-xs font-normal text-muted-foreground tabular-nums">
                                                    {bankAccount.ibanMasked}
                                                </span>
                                            )}
                                    </p>
                                    {bankAccount.bankBalance !== null && (
                                        <span className="text-sm">
                                            <span className="text-muted-foreground">
                                                {t('Bank balance')}{' '}
                                            </span>
                                            <Money
                                                cents={bankAccount.bankBalance}
                                            />
                                        </span>
                                    )}
                                </div>

                                <Select
                                    value={choiceOf(mapping)}
                                    onValueChange={(value) =>
                                        choose(index, value)
                                    }
                                >
                                    <SelectTrigger
                                        className="w-full"
                                        aria-label={t('Account for :name', {
                                            name: bankAccount.name,
                                        })}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="new">
                                            {t('New account')}
                                        </SelectItem>
                                        {accounts.map((account) => (
                                            <SelectItem
                                                key={account.id}
                                                value={String(account.id)}
                                            >
                                                {account.name}
                                            </SelectItem>
                                        ))}
                                        <SelectItem value="ignore">
                                            {t('Don’t sync')}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    message={
                                        errors[`mappings.${index}.account_id`]
                                    }
                                />
                                {bankAccount.isCard && (
                                    <p className="text-xs text-muted-foreground">
                                        {t(
                                            'Card without an IBAN: its payments already appear on the current account. Syncing it too would import them twice.',
                                        )}
                                    </p>
                                )}

                                {mapping.action === 'new' && (
                                    <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem]">
                                        <div>
                                            <Input
                                                aria-label={t(
                                                    'Name of the new account',
                                                )}
                                                value={mapping.name}
                                                onChange={(event) =>
                                                    setMapping(index, {
                                                        name: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `mappings.${index}.name`
                                                    ]
                                                }
                                            />
                                        </div>
                                        <Select
                                            value={mapping.type}
                                            onValueChange={(value) =>
                                                setMapping(index, {
                                                    type: value,
                                                })
                                            }
                                        >
                                            <SelectTrigger
                                                className="w-full"
                                                aria-label={t(
                                                    'Type of the new account',
                                                )}
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {accountTypes.map((type) => (
                                                    <SelectItem
                                                        key={type.value}
                                                        value={type.value}
                                                    >
                                                        {type.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <p className="text-xs text-muted-foreground sm:col-span-2">
                                            {t(
                                                'The account starts today with the bank balance; transactions are imported from tomorrow.',
                                            )}
                                        </p>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save and sync')}
                    </Button>
                </div>
            </form>
        </>
    );
}

MapBankAccounts.layout = (props: {
    currentTeam?: { slug: string } | null;
    connection?: { aspspName: string };
}) => ({
    breadcrumbs: [
        {
            title: t('Bank connections'),
            href: props.currentTeam ? index(props.currentTeam.slug) : '/',
        },
        {
            title: props.connection?.aspspName ?? t('Accounts'),
            href: '#',
        },
    ],
});
