import { Head, router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronLeft, ChevronRight, Wallet } from 'lucide-react';
import { useState } from 'react';
import { IncomeExpense } from '@/components/stats/income-expense';
import { SpendingBreakdown } from '@/components/stats/spending-breakdown';
import { SpendingTrends } from '@/components/stats/spending-trends';
import type { StatsProps } from '@/components/stats/stats-data';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { currentMonth, formatMonth } from '@/lib/dates';
import { t, tc } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show } from '@/routes/stats';

type Tab = 'trends' | 'breakdown' | 'income';

const tabs: Array<{ key: Tab; label: string }> = [
    { key: 'trends', label: t('Spending trends') },
    { key: 'breakdown', label: t('Breakdown') },
    { key: 'income', label: t('Income and spending') },
];

function addMonths(month: string, count: number): string {
    const date = new Date(
        Number(month.slice(0, 4)),
        Number(month.slice(5)) - 1 + count,
        1,
    );

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function monthsBetween(from: string, to: string): number {
    return (
        (Number(to.slice(0, 4)) - Number(from.slice(0, 4))) * 12 +
        Number(to.slice(5)) -
        Number(from.slice(5)) +
        1
    );
}

function tabFromUrl(url: string): Tab {
    const tab = new URL(url, 'http://localhost').searchParams.get('tab');

    return tabs.some((item) => item.key === tab) ? (tab as Tab) : 'trends';
}

/**
 * Spending trends, breakdown and income versus expense over a range of months
 * of the budget accounts.
 */
export default function StatsShow(props: StatsProps) {
    const page = usePage();
    const team = page.props.currentTeam?.slug ?? '';
    const [tab, setTab] = useState<Tab>(() => tabFromUrl(page.url));
    const now = currentMonth();
    const length = monthsBetween(props.from, props.to);
    const year = now.slice(0, 4);

    const visit = (changes: {
        from?: string;
        to?: string;
        accounts?: number[] | null;
        tab?: Tab;
    }) => {
        const accounts =
            changes.accounts === undefined
                ? props.selectedAccounts
                : changes.accounts;

        router.get(
            show(team).url,
            {
                from: changes.from ?? props.from,
                to: changes.to ?? props.to,
                ...(accounts ? { accounts: accounts.join(',') } : {}),
                ...((changes.tab ?? tab) !== 'trends'
                    ? { tab: changes.tab ?? tab }
                    : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const selectTab = (next: Tab) => {
        setTab(next);
        const url = new URL(window.location.href);

        if (next === 'trends') {
            url.searchParams.delete('tab');
        } else {
            url.searchParams.set('tab', next);
        }

        window.history.replaceState(window.history.state, '', url);
    };

    const presets = [
        {
            label: t('Last :count months', { count: 3 }),
            from: addMonths(now, -2),
            to: now,
        },
        {
            label: t('Last :count months', { count: 6 }),
            from: addMonths(now, -5),
            to: now,
        },
        {
            label: t('Last :count months', { count: 12 }),
            from: addMonths(now, -11),
            to: now,
        },
        { label: t('This year'), from: `${year}-01`, to: now },
        {
            label: t('Last year'),
            from: `${Number(year) - 1}-01`,
            to: `${Number(year) - 1}-12`,
        },
    ];

    const openAccounts = props.accounts.filter((account) => !account.closed);
    const selected =
        props.selectedAccounts ?? props.accounts.map((account) => account.id);
    const toggleAccount = (id: number) => {
        const next = selected.includes(id)
            ? selected.filter((item) => item !== id)
            : [...selected, id];

        if (next.length > 0) {
            visit({
                accounts: next.length === props.accounts.length ? null : next,
            });
        }
    };

    return (
        <>
            <Head title={t('Statistics')} />
            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <h1 className="text-2xl font-semibold">{t('Statistics')}</h1>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex items-center rounded-md border">
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={t('Previous period')}
                            onClick={() =>
                                visit({
                                    from: addMonths(props.from, -length),
                                    to: addMonths(props.to, -length),
                                })
                            }
                        >
                            <ChevronLeft />
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost">
                                    {props.from === props.to
                                        ? formatMonth(props.from)
                                        : `${formatMonth(props.from)} – ${formatMonth(props.to)}`}
                                    <ChevronDown className="opacity-60" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start">
                                {presets.map((preset) => (
                                    <DropdownMenuItem
                                        key={preset.label}
                                        onSelect={() =>
                                            visit({
                                                from: preset.from,
                                                to: preset.to,
                                            })
                                        }
                                    >
                                        {preset.label}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={t('Next period')}
                            disabled={props.to >= now}
                            onClick={() =>
                                visit({
                                    from: addMonths(props.from, length),
                                    to: addMonths(props.to, length),
                                })
                            }
                        >
                            <ChevronRight />
                        </Button>
                    </div>

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline">
                                <Wallet />
                                {props.selectedAccounts === null
                                    ? t('All accounts')
                                    : props.selectedAccounts.length === 1
                                      ? props.accounts.find(
                                            (account) =>
                                                account.id ===
                                                props.selectedAccounts![0],
                                        )?.name
                                      : tc(
                                            ':count account|:count accounts',
                                            props.selectedAccounts.length,
                                        )}
                                <ChevronDown className="opacity-60" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" className="w-60">
                            <DropdownMenuItem
                                onSelect={() => visit({ accounts: null })}
                            >
                                {t('All accounts')}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            {openAccounts.map((account) => (
                                <DropdownMenuCheckboxItem
                                    key={account.id}
                                    checked={selected.includes(account.id)}
                                    onSelect={(event) => event.preventDefault()}
                                    onCheckedChange={() =>
                                        toggleAccount(account.id)
                                    }
                                >
                                    {account.name}
                                </DropdownMenuCheckboxItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <nav
                    className="-mx-4 flex gap-1 overflow-x-auto border-b px-4 sm:mx-0 sm:px-0"
                    aria-label={t('Views')}
                >
                    {tabs.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => selectTab(item.key)}
                            aria-current={tab === item.key ? 'page' : undefined}
                            className={cn(
                                '-mb-px shrink-0 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                                tab === item.key
                                    ? 'border-foreground text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {item.label}
                        </button>
                    ))}
                </nav>

                {tab === 'trends' && <SpendingTrends {...props} />}
                {tab === 'breakdown' && (
                    <SpendingBreakdown key={props.to} {...props} />
                )}
                {tab === 'income' && <IncomeExpense {...props} />}
            </div>
        </>
    );
}

StatsShow.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: t('Statistics'),
            href: props.currentTeam ? show(props.currentTeam.slug) : '/',
        },
    ],
});
