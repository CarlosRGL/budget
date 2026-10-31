import { router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMoney, moneyToInput, parseMoney } from '@/lib/money';
import { store, update } from '@/routes/loans';
import type { LoanAccount, LoanCategoryGroup, LoanSummary } from './loan-data';
import { monthlyPayment } from './loan-data';
import { intlLocale, t } from '@/lib/i18n';

type PartDraft = {
    label: string;
    principal: string;
    rate: string;
    months: string;
    firstPaymentDate: string;
    payment: string;
};

const emptyPart = (label: string): PartDraft => ({
    label,
    principal: '',
    rate: '',
    months: '',
    firstPaymentDate: '',
    payment: '',
});

function parseRate(input: string): number | null {
    const value = Number(input.replace(',', '.').replace('%', '').trim());

    return input.trim() !== '' && Number.isFinite(value) && value >= 0
        ? Math.round(value * 1000)
        : null;
}

function draftsOf(loan: LoanSummary | undefined): PartDraft[] {
    if (!loan) {
        return [emptyPart(t('Main loan'))];
    }

    return loan.parts.map((part) => ({
        label: part.label,
        principal: moneyToInput(part.principal),
        rate: (part.rateMilli / 1000).toLocaleString(intlLocale(), {
            maximumFractionDigits: 3,
        }),
        months: String(part.months),
        firstPaymentDate: part.firstPaymentDate,
        payment: part.payment === null ? '' : moneyToInput(part.payment),
    }));
}

/**
 * Create or edit a loan and its lines (main loan, zero-rate loan…).
 */
export function LoanFormDialog({
    loan,
    accounts,
    categoryGroups,
    children,
}: {
    loan?: LoanSummary;
    accounts: LoanAccount[];
    categoryGroups: LoanCategoryGroup[];
    children: ReactNode;
}) {
    const { currentTeam } = usePage().props;
    const [open, setOpen] = useState(false);
    const [name, setName] = useState(loan?.name ?? t('Mortgage'));
    const [accountId, setAccountId] = useState<number | null>(
        loan?.account?.id ?? accounts[0]?.id ?? null,
    );
    const [categoryId, setCategoryId] = useState<number | null>(
        loan?.category?.id ?? null,
    );
    const [parts, setParts] = useState<PartDraft[]>(() => draftsOf(loan));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const changeOpen = (value: boolean) => {
        setOpen(value);

        if (value) {
            setName(loan?.name ?? t('Mortgage'));
            setAccountId(loan?.account?.id ?? accounts[0]?.id ?? null);
            setCategoryId(loan?.category?.id ?? null);
            setParts(draftsOf(loan));
            setErrors({});
        }
    };

    const setPart = (index: number, changes: Partial<PartDraft>) =>
        setParts((current) =>
            current.map((part, i) =>
                i === index ? { ...part, ...changes } : part,
            ),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!currentTeam) {
            return;
        }

        const local: Record<string, string> = {};
        const payload = parts.map((part, index) => {
            const principal = parseMoney(part.principal);
            const rate = parseRate(part.rate);
            const payment =
                part.payment.trim() === '' ? null : parseMoney(part.payment);

            if (principal === null) {
                local[`parts.${index}.principal`] = t('Invalid amount.');
            }

            if (rate === null) {
                local[`parts.${index}.rate_milli`] = t(
                    'Invalid rate, for example 1.14.',
                );
            }

            if (part.payment.trim() !== '' && payment === null) {
                local[`parts.${index}.payment`] = t('Invalid amount.');
            }

            return {
                label: part.label,
                principal,
                rate_milli: rate,
                months: Number(part.months) || null,
                first_payment_date: part.firstPaymentDate || null,
                payment,
            };
        });

        if (Object.keys(local).length > 0) {
            setErrors(local);

            return;
        }

        const data = {
            name,
            account_id: accountId,
            category_id: categoryId,
            parts: payload,
        };
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (serverErrors: Record<string, string>) =>
                setErrors(serverErrors),
            onSuccess: () => setOpen(false),
        };

        if (loan) {
            router.put(
                update({ current_team: currentTeam.slug, loan: loan.id }).url,
                data,
                options,
            );
        } else {
            router.post(store(currentTeam.slug).url, data, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>
                            {loan ? t('Edit loan') : t('New loan')}
                        </DialogTitle>
                        <DialogDescription>
                            {t(
                                'Copy the bank’s “Loan details”. A loan can have several lines, for example a main loan and a zero-rate loan.',
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="loan-name">{t('Name')}</Label>
                            <Input
                                id="loan-name"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label>{t('Linked loan account')}</Label>
                            <Select
                                value={
                                    accountId === null
                                        ? 'none'
                                        : String(accountId)
                                }
                                onValueChange={(value) =>
                                    setAccountId(
                                        value === 'none' ? null : Number(value),
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        {t('None')}
                                    </SelectItem>
                                    {accounts.map((account) => (
                                        <SelectItem
                                            key={account.id}
                                            value={String(account.id)}
                                        >
                                            {account.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.account_id} />
                        </div>
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label>{t('Monthly payment category')}</Label>
                            <Select
                                value={
                                    categoryId === null
                                        ? 'none'
                                        : String(categoryId)
                                }
                                onValueChange={(value) =>
                                    setCategoryId(
                                        value === 'none' ? null : Number(value),
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        {t('No category (none)')}
                                    </SelectItem>
                                    {categoryGroups
                                        .filter(
                                            (group) =>
                                                group.categories.length > 0,
                                        )
                                        .map((group) => (
                                            <SelectGroup key={group.id}>
                                                <SelectLabel>
                                                    {group.name}
                                                </SelectLabel>
                                                {group.categories.map(
                                                    (category) => (
                                                        <SelectItem
                                                            key={category.id}
                                                            value={String(
                                                                category.id,
                                                            )}
                                                        >
                                                            {category.name}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectGroup>
                                        ))}
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'The category where you file the direct debit. Its target follows the monthly payment, and payments are checked there every month.',
                                )}
                            </p>
                            <InputError message={errors.category_id} />
                        </div>
                    </div>

                    {parts.map((part, index) => {
                        const principal = parseMoney(part.principal);
                        const rate = parseRate(part.rate);
                        const preview =
                            principal !== null &&
                            rate !== null &&
                            Number(part.months) > 0
                                ? monthlyPayment(
                                      principal,
                                      rate,
                                      Number(part.months),
                                  )
                                : null;
                        const error = (field: string) =>
                            errors[`parts.${index}.${field}`];

                        return (
                            <fieldset
                                key={index}
                                className="space-y-3 rounded-lg border p-3"
                            >
                                <div className="flex items-center gap-2">
                                    <Input
                                        aria-label={t('Line label')}
                                        value={part.label}
                                        onChange={(event) =>
                                            setPart(index, {
                                                label: event.target.value,
                                            })
                                        }
                                        className="h-8 font-medium"
                                    />
                                    {parts.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 shrink-0"
                                            aria-label={t('Remove line')}
                                            onClick={() =>
                                                setParts((current) =>
                                                    current.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </div>
                                <InputError message={error('label')} />
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                    <div className="grid gap-1.5">
                                        <Label className="text-xs">
                                            {t('Amount borrowed')}
                                        </Label>
                                        <Input
                                            inputMode="decimal"
                                            placeholder={t('235900.00')}
                                            value={part.principal}
                                            onChange={(event) =>
                                                setPart(index, {
                                                    principal:
                                                        event.target.value,
                                                })
                                            }
                                            className="text-right tabular-nums"
                                        />
                                        <InputError
                                            message={error('principal')}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label className="text-xs">
                                            {t('Fixed rate (%)')}
                                        </Label>
                                        <Input
                                            inputMode="decimal"
                                            placeholder={t('1.14')}
                                            value={part.rate}
                                            onChange={(event) =>
                                                setPart(index, {
                                                    rate: event.target.value,
                                                })
                                            }
                                            className="text-right tabular-nums"
                                        />
                                        <InputError
                                            message={error('rate_milli')}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label className="text-xs">
                                            {t('Term (months)')}
                                        </Label>
                                        <Input
                                            inputMode="numeric"
                                            placeholder="300"
                                            value={part.months}
                                            onChange={(event) =>
                                                setPart(index, {
                                                    months: event.target.value,
                                                })
                                            }
                                            className="text-right tabular-nums"
                                        />
                                        <InputError message={error('months')} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label className="text-xs">
                                            {t('First instalment')}
                                        </Label>
                                        <Input
                                            type="date"
                                            value={part.firstPaymentDate}
                                            onChange={(event) =>
                                                setPart(index, {
                                                    firstPaymentDate:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                        <InputError
                                            message={error(
                                                'first_payment_date',
                                            )}
                                        />
                                    </div>
                                    <div className="col-span-2 grid gap-1.5">
                                        <Label className="text-xs">
                                            {t(
                                                'Monthly payment (if different)',
                                            )}
                                        </Label>
                                        <Input
                                            inputMode="decimal"
                                            placeholder={
                                                preview !== null
                                                    ? t('Calculated: :amount', {
                                                          amount: formatMoney(
                                                              preview,
                                                          ),
                                                      })
                                                    : t('Calculated')
                                            }
                                            value={part.payment}
                                            onChange={(event) =>
                                                setPart(index, {
                                                    payment: event.target.value,
                                                })
                                            }
                                            className="tabular-nums"
                                        />
                                        <InputError
                                            message={error('payment')}
                                        />
                                    </div>
                                </div>
                            </fieldset>
                        );
                    })}

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            setParts((current) => [
                                ...current,
                                {
                                    ...emptyPart(t('Zero-rate loan')),
                                    rate: '0',
                                    months: current[0]?.months ?? '',
                                    firstPaymentDate:
                                        current[0]?.firstPaymentDate ?? '',
                                },
                            ])
                        }
                    >
                        <Plus />
                        {t('Add a line')}
                    </Button>
                    <InputError message={errors.parts} />

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                {t('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {loan ? t('Save') : t('Add the loan')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
