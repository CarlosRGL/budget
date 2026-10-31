import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Money } from '@/components/money';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { t } from '@/lib/i18n';
import { show } from '@/routes/accounts';
import type { SidebarAccount, SidebarAccountSection } from '@/types';

export const sectionTitles: Record<SidebarAccountSection['key'], string> = {
    budget: t('Accounts'),
    loans: t('Loan accounts'),
    tracking: t('Tracking'),
};

function AccountItem({
    account,
    teamSlug,
    readOnly,
}: {
    account: SidebarAccount;
    teamSlug: string;
    readOnly: boolean;
}) {
    const { isCurrentUrl } = useCurrentUrl();
    const href = show({ current_team: teamSlug, account: account.id });

    if (readOnly) {
        return (
            <SidebarMenuItem>
                <div className="flex h-8 items-center gap-2 px-2 text-sm">
                    <span className="truncate">{account.name}</span>
                    <Money
                        cents={account.balance}
                        className="ml-auto text-xs text-sidebar-foreground/80"
                    />
                </div>
            </SidebarMenuItem>
        );
    }

    return (
        <SidebarMenuItem>
            <SidebarMenuButton asChild isActive={isCurrentUrl(href)}>
                <Link href={href} prefetch>
                    <span className="truncate">{account.name}</span>
                    <Money
                        cents={account.balance}
                        className="ml-auto text-xs text-sidebar-foreground/80"
                    />
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );
}

/**
 * The household's accounts and their balance. Read-only members see the
 * balances without links to the registers, and without the closed accounts.
 */
export function NavAccounts({ readOnly = false }: { readOnly?: boolean }) {
    const { currentTeam, sidebarAccounts } = usePage().props;

    if (!currentTeam || !sidebarAccounts) {
        return null;
    }

    const { sections, closed } = sidebarAccounts;

    return (
        <div className="group-data-[collapsible=icon]:hidden">
            {sections.map((section) => (
                <SidebarGroup key={section.key} className="py-1">
                    <SidebarGroupLabel className="flex justify-between tracking-wide uppercase">
                        <span>{sectionTitles[section.key]}</span>
                        <Money cents={section.total} />
                    </SidebarGroupLabel>
                    <SidebarMenu>
                        {section.accounts.map((account) => (
                            <AccountItem
                                key={account.id}
                                account={account}
                                teamSlug={currentTeam.slug}
                                readOnly={readOnly}
                            />
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            ))}

            {sections.length === 0 && (
                <p className="px-4 py-2 text-sm text-muted-foreground">
                    {t('No accounts yet.')}
                </p>
            )}

            {closed.length > 0 && !readOnly && (
                <Collapsible asChild className="group/closed">
                    <SidebarGroup className="py-1">
                        <SidebarGroupLabel asChild>
                            <CollapsibleTrigger className="flex w-full items-center gap-1 tracking-wide uppercase">
                                <ChevronRight className="transition-transform group-data-[state=open]/closed:rotate-90" />
                                {t('Closed (:count)', {
                                    count: closed.length,
                                })}
                            </CollapsibleTrigger>
                        </SidebarGroupLabel>
                        <CollapsibleContent>
                            <SidebarMenu className="opacity-70">
                                {closed.map((account) => (
                                    <AccountItem
                                        key={account.id}
                                        account={account}
                                        teamSlug={currentTeam.slug}
                                        readOnly={false}
                                    />
                                ))}
                            </SidebarMenu>
                        </CollapsibleContent>
                    </SidebarGroup>
                </Collapsible>
            )}
        </div>
    );
}
