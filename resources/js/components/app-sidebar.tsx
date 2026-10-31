import { Link, router, usePage } from '@inertiajs/react';
import {
    ChartPie,
    HandCoins,
    Landmark,
    List,
    Receipt,
    Plus,
    Store,
    Users,
    Wallet,
} from 'lucide-react';
import { useEffect } from 'react';
import AppLogo from '@/components/app-logo';
import CreateAccountDialog from '@/components/create-account-dialog';
import { NavAccounts } from '@/components/nav-accounts';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { TeamSwitcher } from '@/components/team-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarSeparator,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useIsViewer } from '@/hooks/use-is-viewer';
import { index as accountsIndex } from '@/routes/accounts';
import { index as loans } from '@/routes/loans';
import { index as payees } from '@/routes/payees';
import { index as bankConnections } from '@/routes/bank-connections';
import { show as budget } from '@/routes/budget';
import { overview, spending } from '@/routes/simple';
import { show as stats } from '@/routes/stats';
import { edit as editTeam } from '@/routes/teams';
import type { NavItem } from '@/types';
import { t } from '@/lib/i18n';

export function AppSidebar() {
    const page = usePage();
    const { isCurrentUrl } = useCurrentUrl();
    const team = page.props.currentTeam;
    const isViewer = useIsViewer();
    const dashboardUrl = team
        ? isViewer
            ? overview({ current_team: team.slug })
            : budget({ current_team: team.slug })
        : '/';
    const { setOpenMobile } = useSidebar();

    // On a phone the sidebar is a sheet over the page: going to another page
    // closes it, whatever link was tapped.
    useEffect(
        () =>
            router.on('start', (event) => {
                if (event.detail.visit.method === 'get') {
                    setOpenMobile(false);
                }
            }),
        [setOpenMobile],
    );

    const mainNavItems: NavItem[] = !team
        ? []
        : isViewer
          ? [
                { title: t('This month'), href: dashboardUrl, icon: Wallet },
                {
                    title: t('Spending'),
                    href: spending({ current_team: team.slug }),
                    icon: Receipt,
                },
                { title: t('Trends'), href: stats(team.slug), icon: ChartPie },
                { title: t('Loans'), href: loans(team.slug), icon: HandCoins },
            ]
          : [
                { title: t('Budget'), href: dashboardUrl, icon: Wallet },
                {
                    title: t('All accounts'),
                    href: accountsIndex(team.slug),
                    icon: List,
                    badge: page.props.inboxCount,
                },
                {
                    title: t('Statistics'),
                    href: stats(team.slug),
                    icon: ChartPie,
                },
                { title: t('Loans'), href: loans(team.slug), icon: HandCoins },
                {
                    title: t('Payees'),
                    href: payees(team.slug),
                    icon: Store,
                },
            ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboardUrl} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <TeamSwitcher />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                <SidebarSeparator className="mx-0" />
                <NavAccounts readOnly={isViewer} />
                {team && !isViewer && (
                    <SidebarGroup className="mt-auto py-1">
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <CreateAccountDialog>
                                    <SidebarMenuButton
                                        tooltip={{
                                            children: t('Add account'),
                                        }}
                                    >
                                        <Plus />
                                        <span>{t('Add account')}</span>
                                    </SidebarMenuButton>
                                </CreateAccountDialog>
                            </SidebarMenuItem>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    asChild
                                    isActive={isCurrentUrl(
                                        bankConnections(team.slug),
                                    )}
                                    tooltip={{
                                        children: t('Bank connections'),
                                    }}
                                >
                                    <Link
                                        href={bankConnections(team.slug)}
                                        prefetch
                                    >
                                        <Landmark />
                                        <span>{t('Bank connections')}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    asChild
                                    isActive={isCurrentUrl(editTeam(team.slug))}
                                    tooltip={{ children: t('Household') }}
                                >
                                    <Link href={editTeam(team.slug)} prefetch>
                                        <Users />
                                        <span>{t('Household')}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroup>
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
