export type NamedRef = { id: number; name: string };

export type AccountRef = NamedRef & { onBudget: boolean };

export type RegisterSplit = {
    id: number;
    category: NamedRef | null;
    inflowToRta: boolean;
    memo: string | null;
    amount: number;
};

export type RegisterTransaction = {
    id: number;
    account: AccountRef;
    date: string;
    payee: NamedRef | null;
    transferAccount: AccountRef | null;
    category: NamedRef | null;
    inflowToRta: boolean;
    isStartingBalance: boolean;
    memo: string | null;
    amount: number;
    cleared: boolean;
    approved: boolean;
    approvedBy: string | null;
    needsCategory: boolean;
    /** Linked to the bank: imported, or typed then reconciled. */
    imported: boolean;
    bankPending: boolean;
    rawLabel: string | null;
    cardSuffix: string | null;
    reviewReason:
        | 'amount_changed'
        | 'removed_by_bank'
        | 'possible_duplicate'
        | null;
    splits: RegisterSplit[];
};

export type Register = {
    filter: 'inbox' | null;
    balances: { cleared: number; uncleared: number; working: number } | null;
    transactions: RegisterTransaction[];
    truncated: boolean;
};

export type PickerCategory = NamedRef & { hidden: boolean; available: number };

export type PickerCategoryGroup = NamedRef & {
    hidden: boolean;
    categories: PickerCategory[];
};

export type TransactionPickers = {
    readyToAssign: number;
    payees: Array<NamedRef & { lastCategoryId: number | null }>;
    categoryGroups: PickerCategoryGroup[];
    accounts: Array<AccountRef & { closed: boolean }>;
};
