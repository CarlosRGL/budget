export type BankConnectionAccount = {
    id: number;
    name: string;
    ibanMasked: string | null;
    account: { id: number; name: string } | null;
    /** Balance given by the bank, in cents. */
    bankBalance: number | null;
    /** Balance computed from the transactions of the linked account. */
    balance: number | null;
};

export type BankConnection = {
    id: number;
    aspspName: string;
    country: string;
    owner: string;
    isOwner: boolean;
    expired: boolean;
    expiresSoon: boolean;
    validUntil: string | null;
    lastSyncedAt: string | null;
    lastError: string | null;
    accounts: BankConnectionAccount[];
};

export type Aspsp = {
    name: string;
    logo: string | null;
    maximumConsentDays: number;
};

export type BankRenewal = {
    id: number;
    aspspName: string;
    owner: string;
    validUntil: string | null;
    expired: boolean;
};

export type RenameRule = {
    id: number;
    contains: string;
    payee: { id: number; name: string };
};
