import { router, usePage } from '@inertiajs/react';
import { Plus, Trash2, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Money } from '@/components/money';
import { CategoryPicker } from '@/components/transactions/category-picker';
import type { CategoryValue } from '@/components/transactions/category-picker';
import { PayeePicker } from '@/components/transactions/payee-picker';
import type { PayeeValue } from '@/components/transactions/payee-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { todayIso } from '@/lib/dates';
import { t, tc } from '@/lib/i18n';
import { moneyToInput, parseMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import { destroy, store, update } from '@/routes/transactions';
import type { RegisterTransaction, TransactionPickers } from '@/types';

type SplitLine = CategoryValue & {
    memo: string;
    outflow: string;
    inflow: string;
};

type Direction = 'out' | 'in';

type EditorState = PayeeValue &
    CategoryValue & {
        accountId: number | null;
        date: string;
        memo: string;
        direction: Direction;
        amountText: string;
        cleared: boolean;
        splits: SplitLine[] | null;
        /** Imported transactions: remember the new payee for this bank label. */
        createRule: boolean;
        ruleContains: string;
    };

type Props = {
    /** The transaction being edited, or null to type a new one. */
    transaction: RegisterTransaction | null;
    /** The account of the register, or null for "every account". */
    accountId: number | null;
    pickers: TransactionPickers;
    open: boolean;
    onDone: () => void;
};

const blankLine = (): SplitLine => ({
    categoryId: null,
    inflowToRta: false,
    memo: '',
    outflow: '',
    inflow: '',
});

function amountFields(cents: number) {
    return {
        outflow: cents < 0 ? moneyToInput(-cents) : '',
        inflow: cents > 0 ? moneyToInput(cents) : '',
    };
}

/** Inflow minus outflow, in cents; null when one of them is not an amount. */
function amountOf(outflow: string, inflow: string): number | null {
    const out = parseMoney(outflow.trim() === '' ? '0' : outflow);
    const into = parseMoney(inflow.trim() === '' ? '0' : inflow);

    return out === null || into === null ? null : into - out;
}

function initialState(
    transaction: RegisterTransaction | null,
    accountId: number | null,
    pickers: TransactionPickers,
): EditorState {
    if (!transaction) {
        return {
            accountId:
                accountId ??
                pickers.accounts.find(
                    (account) => account.onBudget && !account.closed,
                )?.id ??
                null,
            date: todayIso(),
            payeeId: null,
            payeeName: '',
            transferAccountId: null,
            categoryId: null,
            inflowToRta: false,
            memo: '',
            direction: 'out',
            amountText: '',
            cleared: false,
            splits: null,
            createRule: false,
            ruleContains: '',
        };
    }

    return {
        accountId: transaction.account.id,
        date: transaction.date,
        payeeId: transaction.payee?.id ?? null,
        payeeName: transaction.payee?.name ?? '',
        transferAccountId: transaction.transferAccount?.id ?? null,
        categoryId: transaction.category?.id ?? null,
        inflowToRta: transaction.inflowToRta,
        memo: transaction.memo ?? '',
        direction: transaction.amount > 0 ? 'in' : 'out',
        amountText:
            transaction.amount === 0
                ? ''
                : moneyToInput(Math.abs(transaction.amount)),
        cleared: transaction.cleared,
        splits:
            transaction.splits.length > 0
                ? transaction.splits.map((split) => ({
                      categoryId: split.category?.id ?? null,
                      inflowToRta: split.inflowToRta,
                      memo: split.memo ?? '',
                      ...amountFields(split.amount),
                  }))
                : null,
        createRule: false,
        ruleContains: transaction.payee?.name ?? transaction.rawLabel ?? '',
    };
}

/** Signed amount in cents typed in the main field; null when invalid. */
function signedAmount(direction: Direction, text: string): number | null {
    const cents = parseMoney(text.trim() === '' ? '0' : text);

    return cents === null ? null : direction === 'out' ? -cents : cents;
}

/**
 * Type or edit a transaction in a modal. Enter saves, Escape closes.
 */
export function TransactionEditor({
    transaction,
    accountId,
    pickers,
    open,
    onDone,
}: Props) {
    const { currentTeam } = usePage().props;
    const [state, setState] = useState(() =>
        initialState(transaction, accountId, pickers),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const set = (changes: Partial<EditorState>) =>
        setState((current) => ({ ...current, ...changes }));

    const account = pickers.accounts.find(
        (candidate) => candidate.id === state.accountId,
    );
    const transferAccount = pickers.accounts.find(
        (candidate) => candidate.id === state.transferAccountId,
    );
    const isTransfer = transferAccount !== undefined;
    const leavesBudget =
        isTransfer && !!account?.onBudget && !transferAccount.onBudget;
    const needsCategory = !!account?.onBudget && (!isTransfer || leavesBudget);

    const payeeRenamed =
        !!transaction?.imported &&
        !isTransfer &&
        state.payeeName.trim() !== '' &&
        state.payeeName.trim() !== (transaction.payee?.name ?? '');

    const amount = signedAmount(state.direction, state.amountText);
    const splitsTotal = (state.splits ?? []).reduce(
        (total, line) => total + (amountOf(line.outflow, line.inflow) ?? 0),
        0,
    );
    const remaining = (amount ?? 0) - splitsTotal;

    const setLine = (index: number, changes: Partial<SplitLine>) =>
        set({
            splits: (state.splits ?? []).map((line, position) =>
                position === index ? { ...line, ...changes } : line,
            ),
        });

    const startSplit = () =>
        set({
            categoryId: null,
            inflowToRta: false,
            splits: [
                {
                    ...blankLine(),
                    categoryId: state.categoryId,
                    inflowToRta: state.inflowToRta,
                    ...amountFields(amount ?? 0),
                },
                blankLine(),
            ],
        });

    const submit = () => {
        if (!currentTeam || processing) {
            return;
        }

        if (amount === null) {
            setErrors({ amount: t('Invalid amount, for example 18.09') });

            return;
        }

        const splits = state.splits?.map((line) => ({
            category_id: line.inflowToRta ? null : line.categoryId,
            inflow_to_rta: line.inflowToRta,
            memo: line.memo || null,
            amount: amountOf(line.outflow, line.inflow),
        }));

        if (splits?.some((line) => line.amount === null)) {
            setErrors({ splits: t('An amount of the split is invalid.') });

            return;
        }

        const payload = {
            account_id: state.accountId,
            date: state.date,
            payee_id: isTransfer ? null : state.payeeId,
            payee_name: isTransfer ? null : state.payeeName || null,
            transfer_account_id: state.transferAccountId,
            category_id: state.inflowToRta ? null : state.categoryId,
            inflow_to_rta: state.inflowToRta,
            memo: state.memo || null,
            amount,
            cleared: state.cleared,
            splits: isTransfer ? [] : (splits ?? []),
            rename_rule_contains:
                payeeRenamed && state.createRule
                    ? state.ruleContains.trim() || null
                    : null,
        };

        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: onDone,
            onError: (bag: Record<string, string>) => setErrors(bag),
        };

        if (transaction) {
            router.patch(
                update({
                    current_team: currentTeam.slug,
                    transaction: transaction.id,
                }).url,
                payload,
                options,
            );
        } else {
            router.post(store(currentTeam.slug).url, payload, options);
        }
    };

    const remove = () => {
        if (!currentTeam || !transaction) {
            return;
        }

        router.delete(
            destroy({
                current_team: currentTeam.slug,
                transaction: transaction.id,
            }).url,
            { preserveScroll: true, onSuccess: onDone },
        );
    };

    const errorMessages = Object.values(errors);

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onDone()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
                data-test="transaction-editor"
            >
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {transaction
                                ? t('Edit transaction')
                                : t('New transaction')}
                        </DialogTitle>
                        <DialogDescription>
                            {transaction && !transaction.approved
                                ? t('Saving also approves the transaction.')
                                : t('Enter saves, Escape closes.')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        {accountId === null && (
                            <Field label={t('Account')}>
                                <Select
                                    value={
                                        state.accountId
                                            ? String(state.accountId)
                                            : ''
                                    }
                                    onValueChange={(value) =>
                                        set({ accountId: Number(value) })
                                    }
                                >
                                    <SelectTrigger
                                        className="w-full"
                                        aria-label={t('Account')}
                                    >
                                        <SelectValue
                                            placeholder={t('Account')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {pickers.accounts
                                            .filter(
                                                (candidate) =>
                                                    !candidate.closed,
                                            )
                                            .map((candidate) => (
                                                <SelectItem
                                                    key={candidate.id}
                                                    value={String(candidate.id)}
                                                >
                                                    {candidate.name}
                                                </SelectItem>
                                            ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        )}
                        <Field label={t('Date')}>
                            <Input
                                type="date"
                                aria-label={t('Date')}
                                value={state.date}
                                onChange={(event) =>
                                    set({ date: event.target.value })
                                }
                            />
                        </Field>
                    </div>

                    <Field label={t('Payee')}>
                        <PayeePicker
                            value={state}
                            pickers={pickers}
                            accountId={state.accountId}
                            autoFocus={!transaction}
                            onChange={(payee, lastCategoryId) =>
                                set({
                                    ...payee,
                                    ...(lastCategoryId !== null &&
                                    state.categoryId === null &&
                                    !state.inflowToRta &&
                                    state.splits === null
                                        ? { categoryId: lastCategoryId }
                                        : {}),
                                })
                            }
                        />
                    </Field>

                    {payeeRenamed && (
                        <div className="space-y-2 rounded-lg border border-dashed p-3 text-sm">
                            <label className="flex items-center gap-2">
                                <Checkbox
                                    checked={state.createRule}
                                    onCheckedChange={(checked) =>
                                        set({ createRule: checked === true })
                                    }
                                />
                                {t('Also rename the next imports')}
                            </label>
                            {state.createRule && (
                                <div className="flex flex-wrap items-center gap-2 pl-6">
                                    <span className="text-muted-foreground">
                                        {t('If the label contains')}
                                    </span>
                                    <Input
                                        aria-label={t('Label text')}
                                        className="h-8 w-56"
                                        value={state.ruleContains}
                                        onChange={(event) =>
                                            set({
                                                ruleContains:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                    <span className="text-muted-foreground">
                                        →{' '}
                                        {t('“:name”', {
                                            name: state.payeeName.trim(),
                                        })}
                                    </span>
                                </div>
                            )}
                            {transaction?.rawLabel && (
                                <p className="pl-6 text-xs text-muted-foreground">
                                    {t('Bank label:')} {transaction.rawLabel}
                                </p>
                            )}
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-[auto_minmax(0,1fr)]">
                        <Field label={t('Amount')}>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={state.direction}
                                onValueChange={(value) =>
                                    value &&
                                    set({ direction: value as Direction })
                                }
                                aria-label={t('Direction')}
                            >
                                <ToggleGroupItem value="out" className="px-3">
                                    {t('Outflow')}
                                </ToggleGroupItem>
                                <ToggleGroupItem value="in" className="px-3">
                                    {t('Inflow')}
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </Field>
                        <Field label=" ">
                            <Input
                                aria-label={t('Amount')}
                                placeholder={moneyToInput(0)}
                                inputMode="decimal"
                                className={cn(
                                    'text-right text-base font-medium tabular-nums',
                                    state.direction === 'in' &&
                                        'text-emerald-600 dark:text-emerald-400',
                                )}
                                value={state.amountText}
                                onChange={(event) =>
                                    set({ amountText: event.target.value })
                                }
                            />
                        </Field>
                    </div>

                    {state.splits === null && (
                        <Field label={t('Category')}>
                            {needsCategory ? (
                                <CategoryPicker
                                    value={state}
                                    pickers={pickers}
                                    onChange={(category) => set(category)}
                                    onSplit={
                                        isTransfer ? undefined : startSplit
                                    }
                                />
                            ) : (
                                <p className="flex h-9 items-center text-sm text-muted-foreground">
                                    {isTransfer
                                        ? t('Internal transfer: no category.')
                                        : t('Off-budget account: no category.')}
                                </p>
                            )}
                        </Field>
                    )}

                    {state.splits !== null && (
                        <div className="space-y-2 rounded-lg border p-3">
                            <p className="text-sm font-medium">
                                {tc(
                                    'Split into :count line|Split into :count lines',
                                    state.splits.length,
                                )}
                            </p>
                            {state.splits.map((line, index) => (
                                <div
                                    key={index}
                                    className="grid grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_6.5rem_6.5rem_auto] items-center gap-2"
                                >
                                    <CategoryPicker
                                        value={line}
                                        pickers={pickers}
                                        onChange={(category) =>
                                            setLine(index, category)
                                        }
                                    />
                                    <Input
                                        aria-label={t('Line memo')}
                                        placeholder={t('Memo')}
                                        value={line.memo}
                                        onChange={(event) =>
                                            setLine(index, {
                                                memo: event.target.value,
                                            })
                                        }
                                    />
                                    <Input
                                        aria-label={t('Line outflow')}
                                        placeholder={t('Outflow')}
                                        inputMode="decimal"
                                        className="text-right tabular-nums"
                                        value={line.outflow}
                                        onChange={(event) =>
                                            setLine(index, {
                                                outflow: event.target.value,
                                                inflow: '',
                                            })
                                        }
                                    />
                                    <Input
                                        aria-label={t('Line inflow')}
                                        placeholder={t('Inflow')}
                                        inputMode="decimal"
                                        className="text-right tabular-nums"
                                        value={line.inflow}
                                        onChange={(event) =>
                                            setLine(index, {
                                                inflow: event.target.value,
                                                outflow: '',
                                            })
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('Remove the line')}
                                        onClick={() =>
                                            set({
                                                splits:
                                                    state.splits!.length > 2
                                                        ? state.splits!.filter(
                                                              (_, position) =>
                                                                  position !==
                                                                  index,
                                                          )
                                                        : null,
                                            })
                                        }
                                    >
                                        <X />
                                    </Button>
                                </div>
                            ))}
                            <div className="flex items-center justify-between text-sm">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        set({
                                            splits: [
                                                ...state.splits!,
                                                blankLine(),
                                            ],
                                        })
                                    }
                                >
                                    <Plus />
                                    {t('Add a line')}
                                </Button>
                                <span
                                    className={
                                        remaining === 0
                                            ? 'text-muted-foreground'
                                            : 'font-medium text-amber-600 dark:text-amber-400'
                                    }
                                >
                                    {t('Left to split:')}{' '}
                                    <Money cents={remaining} />
                                </span>
                            </div>
                        </div>
                    )}

                    <Field label={t('Memo')}>
                        <Input
                            aria-label={t('Memo')}
                            placeholder={t('Optional')}
                            value={state.memo}
                            onChange={(event) =>
                                set({ memo: event.target.value })
                            }
                        />
                    </Field>

                    <label className="flex w-fit items-center gap-2 text-sm">
                        <Checkbox
                            checked={state.cleared}
                            onCheckedChange={(checked) =>
                                set({ cleared: checked === true })
                            }
                        />
                        {t('Cleared (seen on the statement)')}
                    </label>

                    {errorMessages.length > 0 && (
                        <ul className="text-sm text-red-600 dark:text-red-400">
                            {errorMessages.map((message) => (
                                <li key={message}>{message}</li>
                            ))}
                        </ul>
                    )}

                    <DialogFooter className="items-center sm:justify-between">
                        <div>
                            {transaction && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="text-red-600 dark:text-red-400"
                                    onClick={remove}
                                >
                                    <Trash2 />
                                    {t('Delete')}
                                </Button>
                            )}
                        </div>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onDone}
                            >
                                {t('Cancel')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    processing ||
                                    (state.splits !== null && remaining !== 0)
                                }
                            >
                                {transaction ? t('Save') : t('Add')}
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label className="h-4">{label}</Label>
            {children}
        </div>
    );
}
