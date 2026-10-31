import { usePage } from '@inertiajs/react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { BankRenewalBanner } from '@/components/bank-renewal-banner';
import { MobileTabBar } from '@/components/mobile-tab-bar';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const page = usePage();
    // A new key plays the entrance again. The budget keeps its state from one
    // month to the next (filters, selection): it animates its own content.
    const view =
        page.component === 'budget/show'
            ? page.component
            : page.url.split('?')[0];

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            {/* Room for the tab bar and the iPhone home indicator on phones. */}
            <AppContent
                variant="sidebar"
                className="min-w-0 overflow-x-clip pb-[calc(4rem+env(safe-area-inset-bottom))] md:pb-0"
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <BankRenewalBanner />
                <div
                    key={view}
                    className="fx-route flex min-w-0 flex-1 flex-col"
                >
                    {children}
                </div>
            </AppContent>
            <MobileTabBar />
        </AppShell>
    );
}
