import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CircleHelp,
    Landmark,
    Link2Off,
    Plus,
    RefreshCw,
    RotateCcw,
    Settings2,
    Tags,
} from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Money } from '@/components/money';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import { show as showAccount } from '@/routes/accounts';
import {
    accounts as mapAccounts,
    destroy,
    index,
    renew,
    setup,
    store,
    sync,
} from '@/routes/bank-connections';
import { index as renameRules } from '@/routes/rename-rules';
import type { Aspsp, BankConnection } from '@/types';
import { intlLocale, t, tc } from '@/lib/i18n';

type Props = {
    configured: boolean;
    connections: BankConnection[];
    aspsps?: Aspsp[];
};

const regionNames = new Intl.DisplayNames(intlLocale(), { type: 'region' });

const countries = ['FR', 'BE', 'LU', 'ES', 'PT', 'DE', 'IT', 'NL'].map(
    (code) => ({ code, name: regionNames.of(code) ?? code }),
);

const dateFormat = new Intl.DateTimeFormat(intlLocale(), { dateStyle: 'long' });
const dateTimeFormat = new Intl.DateTimeFormat(intlLocale(), {
    dateStyle: 'short',
    timeStyle: 'short',
});

function ConnectBankDialog({ aspsps }: { aspsps?: Aspsp[] }) {
    const { currentTeam } = usePage().props;
    const [open, setOpen] = useState(false);
    const [country, setCountry] = useState('FR');
    const [loading, setLoading] = useState(false);
    const [connecting, setConnecting] = useState<string | null>(null);

    const loadBanks = (code: string) => {
        setCountry(code);
        router.reload({
            only: ['aspsps'],
            data: { country: code },
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    };

    const connect = (aspsp: Aspsp) => {
        if (!currentTeam) {
            return;
        }

        setConnecting(aspsp.name);
        router.post(
            store(currentTeam.slug).url,
            { country, aspsp_name: aspsp.name },
            { onFinish: () => setConnecting(null) },
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    loadBanks(country);
                }
            }}
        >
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    {t('Connect a bank')}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('Connect a bank')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'You will be redirected to your bank to allow reading your accounts. Only the account holder can do this.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <Select value={country} onValueChange={loadBanks}>
                    <SelectTrigger className="w-full" aria-label={t('Country')}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {countries.map((option) => (
                            <SelectItem key={option.code} value={option.code}>
                                {option.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Command className="rounded-lg border">
                    <CommandInput placeholder={t('Search for a bank…')} />
                    <CommandList className="max-h-72">
                        <CommandEmpty>
                            {loading
                                ? t('Loading banks…')
                                : t('No bank found.')}
                        </CommandEmpty>
                        {!loading && (
                            <CommandGroup>
                                {(aspsps ?? []).map((aspsp) => (
                                    <CommandItem
                                        key={aspsp.name}
                                        value={aspsp.name}
                                        disabled={connecting !== null}
                                        onSelect={() => connect(aspsp)}
                                    >
                                        {aspsp.logo ? (
                                            <img
                                                src={aspsp.logo}
                                                alt=""
                                                className="size-5 object-contain"
                                            />
                                        ) : (
                                            <Landmark className="size-5 text-muted-foreground" />
                                        )}
                                        <span className="flex-1">
                                            {aspsp.name}
                                        </span>
                                        {connecting === aspsp.name && (
                                            <RefreshCw className="size-4 animate-spin" />
                                        )}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        )}
                    </CommandList>
                </Command>
            </DialogContent>
        </Dialog>
    );
}

function ConnectionStatus({ connection }: { connection: BankConnection }) {
    if (connection.expired) {
        return <Badge variant="destructive">{t('Access expired')}</Badge>;
    }

    if (connection.expiresSoon) {
        return (
            <Badge className="bg-amber-400 text-amber-950 hover:bg-amber-400">
                {t('Expires soon')}
            </Badge>
        );
    }

    return (
        <Badge className="bg-emerald-600 text-white hover:bg-emerald-600">
            {t('Active')}
        </Badge>
    );
}

function DisconnectDialog({ connection }: { connection: BankConnection }) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm" className="text-red-600">
                    <Link2Off />
                    {t('Disconnect')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('Disconnect :bank?', {
                            bank: connection.aspspName,
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t(
                            'Access to the bank is revoked and the accounts stop syncing. Transactions already imported stay in your accounts.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">{t('Cancel')}</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        onClick={() =>
                            router.delete(
                                destroy({
                                    current_team: currentTeam.slug,
                                    bank_connection: connection.id,
                                }).url,
                            )
                        }
                    >
                        {t('Disconnect')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ConnectionCard({ connection }: { connection: BankConnection }) {
    const { currentTeam } = usePage().props;
    const [syncing, setSyncing] = useState(false);

    if (!currentTeam) {
        return null;
    }

    const params = {
        current_team: currentTeam.slug,
        bank_connection: connection.id,
    };

    // Accounts left out ("Don’t sync") are only counted: they are
    // chosen on the accounts page.
    const synced = connection.accounts.filter(
        (bankAccount) => bankAccount.account !== null,
    );
    const ignored = connection.accounts.length - synced.length;

    return (
        <section className="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            <header className="flex flex-wrap items-start justify-between gap-3 bg-muted/50 px-4 py-3">
                <div className="space-y-1">
                    <div className="flex items-center gap-2">
                        <Landmark className="size-4 text-muted-foreground" />
                        <h2 className="font-medium">{connection.aspspName}</h2>
                        <ConnectionStatus connection={connection} />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t('Connected by :owner', { owner: connection.owner })}
                        {connection.validUntil &&
                            ` · ${t(
                                connection.expired
                                    ? 'access expired on :date'
                                    : 'access valid until :date',
                                {
                                    date: dateFormat.format(
                                        new Date(connection.validUntil),
                                    ),
                                },
                            )}`}
                        {connection.lastSyncedAt &&
                            ` · ${t('last sync :date', {
                                date: dateTimeFormat.format(
                                    new Date(connection.lastSyncedAt),
                                ),
                            })}`}
                    </p>
                </div>
                <div className="flex flex-wrap gap-1">
                    {!connection.expired && (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={syncing}
                            onClick={() =>
                                router.post(
                                    sync(params).url,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setSyncing(true),
                                        onFinish: () => setSyncing(false),
                                    },
                                )
                            }
                        >
                            <RefreshCw
                                className={cn(syncing && 'animate-spin')}
                            />
                            {t('Sync now')}
                        </Button>
                    )}
                    {connection.isOwner && (
                        <Button
                            size="sm"
                            variant={
                                connection.expired || connection.expiresSoon
                                    ? 'default'
                                    : 'ghost'
                            }
                            onClick={() => router.post(renew(params).url)}
                        >
                            <RotateCcw />
                            {t('Renew')}
                        </Button>
                    )}
                    <Button size="sm" variant="ghost" asChild>
                        <Link href={mapAccounts(params)}>
                            <Settings2 />
                            {t('Accounts')}
                        </Link>
                    </Button>
                    <DisconnectDialog connection={connection} />
                </div>
            </header>

            {!connection.isOwner &&
                (connection.expired || connection.expiresSoon) && (
                    <p className="border-t px-4 py-2 text-sm text-amber-700 dark:text-amber-400">
                        {t(
                            'The renewal must be done by :owner, from their own account: it is their access to their bank.',
                            { owner: connection.owner },
                        )}
                    </p>
                )}

            {connection.lastError && (
                <p className="flex items-center gap-2 border-t px-4 py-2 text-sm text-red-600 dark:text-red-400">
                    <AlertTriangle className="size-4 shrink-0" />
                    {t('Last sync failed: :error', {
                        error: connection.lastError,
                    })}
                </p>
            )}

            <ul className="divide-y divide-sidebar-border/70 border-t dark:divide-sidebar-border">
                {synced.map((bankAccount) => {
                    const gap =
                        bankAccount.bankBalance !== null &&
                        bankAccount.balance !== null
                            ? bankAccount.balance - bankAccount.bankBalance
                            : null;

                    return (
                        <li
                            key={bankAccount.id}
                            className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-2.5 text-sm"
                        >
                            <div>
                                <p>
                                    {bankAccount.name}
                                    {bankAccount.ibanMasked &&
                                        !bankAccount.name.includes(
                                            bankAccount.ibanMasked,
                                        ) && (
                                            <span className="ml-2 text-xs text-muted-foreground tabular-nums">
                                                {bankAccount.ibanMasked}
                                            </span>
                                        )}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {bankAccount.account
                                        ? `→ ${bankAccount.account.name}`
                                        : t('Not synced')}
                                </p>
                            </div>
                            <div className="text-right">
                                {bankAccount.bankBalance !== null && (
                                    <p>
                                        <span className="text-xs text-muted-foreground">
                                            {t('Bank')}{' '}
                                        </span>
                                        <Money
                                            cents={bankAccount.bankBalance}
                                        />
                                    </p>
                                )}
                                {gap !== null &&
                                    gap !== 0 &&
                                    bankAccount.account && (
                                        <Link
                                            href={showAccount({
                                                current_team: currentTeam.slug,
                                                account: bankAccount.account.id,
                                            })}
                                            className="block text-xs text-amber-700 underline-offset-2 hover:underline dark:text-amber-400"
                                            title={t(
                                                'Open the account to find the difference or reconcile it',
                                            )}
                                        >
                                            {t(
                                                gap > 0
                                                    ? 'The account has :amount more than the bank'
                                                    : 'The account has :amount less than the bank',
                                                {
                                                    amount: formatMoney(
                                                        Math.abs(gap),
                                                    ),
                                                },
                                            )}
                                        </Link>
                                    )}
                                {gap === 0 && (
                                    <p className="text-xs text-emerald-600 dark:text-emerald-400">
                                        {t('Balances match')}
                                    </p>
                                )}
                            </div>
                        </li>
                    );
                })}
                {ignored > 0 && (
                    <li className="px-4 py-2.5 text-xs text-muted-foreground">
                        {tc(
                            ':count other account not synced|:count other accounts not synced',
                            ignored,
                        )}{' '}
                        ·{' '}
                        <Link
                            href={mapAccounts(params)}
                            className="underline underline-offset-2 hover:text-foreground"
                        >
                            {t('choose the accounts')}
                        </Link>
                    </li>
                )}
            </ul>
        </section>
    );
}

export default function BankConnections({
    configured,
    connections,
    aspsps,
}: Props) {
    const { currentTeam } = usePage().props;

    return (
        <>
            <Head title={t('Bank connections')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={t('Bank connections')}
                        description={t(
                            'Your accounts sync with the bank up to 4 times a day. Imported transactions arrive to be approved.',
                        )}
                    />
                    <div className="flex gap-2">
                        {currentTeam && (
                            <Button variant="outline" asChild>
                                <Link href={renameRules(currentTeam.slug)}>
                                    <Tags />
                                    {t('Renaming rules')}
                                </Link>
                            </Button>
                        )}
                        {configured && <ConnectBankDialog aspsps={aspsps} />}
                    </div>
                </div>

                {!configured && currentTeam && (
                    <section className="flex flex-col gap-4 rounded-xl border border-amber-400/60 bg-amber-400/10 p-4 sm:flex-row sm:items-center sm:justify-between md:p-5">
                        <div className="flex gap-3">
                            <Landmark className="mt-0.5 size-5 shrink-0 text-amber-700 dark:text-amber-400" />
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    {t('Bank sync is not set up yet')}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'This instance needs its own EnableBanking application before members can connect their bank. The guide walks you through it, step by step.',
                                    )}
                                </p>
                            </div>
                        </div>
                        <Button asChild className="shrink-0">
                            <Link
                                href={setup({
                                    current_team: currentTeam.slug,
                                })}
                            >
                                {t('Set up bank sync')}
                                <ArrowRight />
                            </Link>
                        </Button>
                    </section>
                )}

                {connections.length === 0 && configured && (
                    <div className="flex flex-col items-center gap-3 py-12 text-center">
                        <Landmark className="size-10 text-muted-foreground" />
                        <p className="max-w-md text-muted-foreground">
                            {t(
                                'No bank connected. Connect your bank to import your transactions automatically.',
                            )}
                        </p>
                    </div>
                )}

                {connections.map((connection) => (
                    <ConnectionCard
                        key={connection.id}
                        connection={connection}
                    />
                ))}

                {configured && currentTeam && (
                    <p className="text-center text-xs text-muted-foreground">
                        <Link
                            href={setup({ current_team: currentTeam.slug })}
                            className="inline-flex items-center gap-1 underline-offset-2 hover:text-foreground hover:underline"
                        >
                            <CircleHelp className="size-3.5" />
                            {t('Setup guide')}
                        </Link>
                    </p>
                )}
            </div>
        </>
    );
}

BankConnections.layout = (props: {
    currentTeam?: { slug: string } | null;
}) => ({
    breadcrumbs: [
        {
            title: t('Bank connections'),
            href: props.currentTeam ? index(props.currentTeam.slug) : '/',
        },
    ],
});
