import { Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { ChartPie, HandCoins, List, Menu, Receipt, Wallet } from 'lucide-react';
import { useSidebar } from '@/components/ui/sidebar';
import { useIsViewer } from '@/hooks/use-is-viewer';
import { cn } from '@/lib/utils';
import { index as accountsIndex } from '@/routes/accounts';
import { show as budget } from '@/routes/budget';
import { index as loans } from '@/routes/loans';
import { overview, spending } from '@/routes/simple';
import { show as stats } from '@/routes/stats';
import { t } from '@/lib/i18n';

const tabClass =
    'relative flex flex-1 flex-col items-center justify-center gap-0.5 pt-2 pb-1 text-[11px] font-medium transition-colors';

function Tab({
    href,
    icon: Icon,
    label,
    active,
    badge = 0,
}: {
    href: string;
    icon: LucideIcon;
    label: string;
    active: boolean;
    badge?: number;
}) {
    return (
        <Link
            href={href}
            // Phones have no hover: load the tabs ahead, so a tap is instant.
            prefetch="mount"
            cacheFor="30s"
            className={cn(
                tabClass,
                active ? 'text-foreground' : 'text-muted-foreground',
            )}
            aria-current={active ? 'page' : undefined}
        >
            <span className="relative">
                <Icon className="size-5" />
                {badge > 0 && (
                    <span className="absolute -top-1.5 -right-2.5 min-w-4 rounded-full bg-amber-400 px-1 text-center text-[10px] leading-4 text-amber-950">
                        {badge}
                    </span>
                )}
            </span>
            {label}
        </Link>
    );
}

/**
 * Thumb-friendly navigation on phones; "Plus" opens the sidebar (accounts,
 * payees, bank connections, household).
 */
export function MobileTabBar() {
    const page = usePage();
    const { setOpenMobile } = useSidebar();
    const team = page.props.currentTeam;
    const isViewer = useIsViewer();

    if (!team) {
        return null;
    }

    const path = page.url.split('?')[0];
    const overviewUrl = overview({ current_team: team.slug }).url;
    const spendingUrl = spending({ current_team: team.slug }).url;
    const loansUrl = loans(team.slug).url;
    const budgetUrl = budget({ current_team: team.slug }).url;
    const accountsUrl = accountsIndex(team.slug).url;
    const statsUrl = stats(team.slug).url;

    return (
        <nav
            aria-label={t('Main navigation')}
            className="fixed inset-x-0 bottom-0 z-40 flex border-t border-sidebar-border/70 bg-background/90 pb-[env(safe-area-inset-bottom)] backdrop-blur-md md:hidden dark:border-sidebar-border"
        >
            {isViewer ? (
                <>
                    <Tab
                        href={overviewUrl}
                        icon={Wallet}
                        label={t('This month')}
                        active={path.startsWith(overviewUrl)}
                    />
                    <Tab
                        href={spendingUrl}
                        icon={Receipt}
                        label={t('Spending')}
                        active={path.startsWith(spendingUrl)}
                    />
                    <Tab
                        href={statsUrl}
                        icon={ChartPie}
                        label={t('Trends')}
                        active={path.startsWith(statsUrl)}
                    />
                    <Tab
                        href={loansUrl}
                        icon={HandCoins}
                        label={t('Loans')}
                        active={path.startsWith(loansUrl)}
                    />
                </>
            ) : (
                <>
                    <Tab
                        href={budgetUrl}
                        icon={Wallet}
                        label={t('Budget')}
                        active={path.startsWith(budgetUrl)}
                    />
                    <Tab
                        href={accountsUrl}
                        icon={List}
                        label={t('Accounts')}
                        active={path.startsWith(accountsUrl)}
                        badge={page.props.inboxCount}
                    />
                    <Tab
                        href={statsUrl}
                        icon={ChartPie}
                        label={t('Stats')}
                        active={path.startsWith(statsUrl)}
                    />
                </>
            )}
            <button
                type="button"
                onClick={() => setOpenMobile(true)}
                className={cn(tabClass, 'text-muted-foreground')}
            >
                <Menu className="size-5" />
                {t('More')}
            </button>
        </nav>
    );
}
