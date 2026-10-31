export type AccountTypeValue =
    | 'checking'
    | 'savings'
    | 'cash'
    | 'loan'
    | 'investment';

export type AccountTypeOption = {
    value: AccountTypeValue;
    label: string;
    onBudgetByDefault: boolean;
};

export type SidebarAccount = {
    id: number;
    name: string;
    type: AccountTypeValue;
    onBudget: boolean;
    balance: number;
};

export type SidebarAccountSection = {
    key: 'budget' | 'loans' | 'tracking';
    total: number;
    accounts: SidebarAccount[];
};

export type SidebarAccounts = {
    sections: SidebarAccountSection[];
    closed: SidebarAccount[];
    types: AccountTypeOption[];
};

export type AccountDetails = {
    id: number;
    name: string;
    type: AccountTypeValue;
    typeLabel: string;
    onBudget: boolean;
    note: string | null;
    closed: boolean;
    startingDate: string;
    balance: number;
    canChangeBudgetStatus: boolean;
    /** Last balance given by the bank, when the account is synced. */
    bankBalance: number | null;
};
