import { intlLocale, t, tc } from '@/lib/i18n';

export type LoanPart = {
    id: number;
    label: string;
    principal: number;
    rateMilli: number;
    months: number;
    firstPaymentDate: string;
    payment: number | null;
    computedPayment: number;
    remaining: number;
};

export type LoanSummary = {
    id: number;
    name: string;
    category: { id: number; name: string } | null;
    account: { id: number; name: string; balance: number } | null;
    principal: number;
    remaining: number;
    repaid: number;
    payment: number;
    nextPaymentDate: string | null;
    totalInterest: number;
    paidInterest: number;
    endDate: string | null;
    parts: LoanPart[];
};

export type LoanMonth = {
    month: string;
    balances: number[];
    interest: number;
    principal: number;
};

export type LoanAccount = { id: number; name: string };

export type LoanCategoryGroup = {
    id: number;
    name: string;
    categories: { id: number; name: string }[];
};

export type LoanPayment = {
    month: string;
    dueDate: string;
    expected: number;
    paid: number;
    status: 'paid' | 'different' | 'missing' | 'upcoming';
    transactions: {
        id: number;
        date: string;
        payee: string | null;
        account: string | null;
        amount: number;
    }[];
};

const longDate = new Intl.DateTimeFormat(intlLocale(), {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

export function formatLongDate(isoDate: string): string {
    return longDate.format(new Date(`${isoDate}T12:00:00`));
}

const rateFormat = new Intl.NumberFormat(intlLocale(), {
    style: 'percent',
    minimumFractionDigits: 2,
    maximumFractionDigits: 3,
});

export function formatRate(rateMilli: number): string {
    return rateFormat.format(rateMilli / 100_000);
}

/**
 * "12 years and 6 months" from today to the given date.
 */
export function timeLeft(isoDate: string): string {
    const now = new Date();
    const end = new Date(`${isoDate}T12:00:00`);
    const months = Math.max(
        0,
        (end.getFullYear() - now.getFullYear()) * 12 +
            end.getMonth() -
            now.getMonth(),
    );
    const years = Math.floor(months / 12);
    const rest = months % 12;
    const yearsText = tc(':count year|:count years', years);
    const monthsText = tc(':count month|:count months', rest);

    if (years > 0 && rest > 0) {
        return t(':years and :months', {
            years: yearsText,
            months: monthsText,
        });
    }

    if (years > 0) {
        return yearsText;
    }

    return rest > 0 ? monthsText : t('finished');
}

/**
 * Same formula as the server (App\Loans\Amortization), for the form preview.
 */
export function monthlyPayment(
    principal: number,
    rateMilli: number,
    months: number,
): number {
    if (months <= 0) {
        return 0;
    }

    if (rateMilli === 0) {
        return Math.round(principal / months);
    }

    const rate = rateMilli / 100_000 / 12;

    return Math.round((principal * rate) / (1 - (1 + rate) ** -months));
}
