import type { SidebarAccounts } from '@/types/accounts';
import type { Auth } from '@/types/auth';
import type { BankRenewal } from '@/types/banking';
import type { Team } from '@/types/teams';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            locale: string;
            locales: Record<string, string>;
            auth: Auth;
            sidebarOpen: boolean;
            currentTeam: Team | null;
            teams: Team[];
            sidebarAccounts: SidebarAccounts | null;
            inboxCount: number;
            bankRenewals: BankRenewal[];
            [key: string]: unknown;
        };
    }
}
