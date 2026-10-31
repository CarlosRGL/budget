import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeftRight,
    CheckCheck,
    Check,
    CircleCheck,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import { Money } from '@/components/money';
import { TransactionEditor } from '@/components/transactions/transaction-editor';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { approve, cleared, dismissReview, merge } from '@/routes/transactions';
import type {
    Register,
    RegisterTransaction,
    TransactionPickers,
} from '@/types';
import { intlLocale, t } from '@/lib/i18n';

type Props = {
    register: Register;
    /** Loaded with the page; absent until then on partial reloads. */
    pickers?: TransactionPickers;
    /** The account of this register, or null for "every account". */
    accountId: number | null;
    /** URL of this register, used by the "All / To review" filter. */
    url: string;
};

const dateFormat = new Intl.DateTimeFormat(intlLocale(), {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
});

function formatDate(isoDate: string): string {
    return dateFormat.format(new Date(`${isoDate}T12:00:00`));
}

function CategoryCell({ transaction }: { transaction: RegisterTransaction }) {
    if (transaction.splits.length > 0) {
        return (
            <span className="text-muted-foreground">
                {t('Split (:count)', { count: transaction.splits.length })}
            </span>
        );
    }

    if (transaction.inflowToRta) {
        return <span>{t('Ready to Assign')}</span>;
    }

    if (transaction.category) {
        return <span>{transaction.category.name}</span>;
    }

    if (transaction.needsCategory) {
        return (
            <Badge className="bg-amber-400 text-amber-950 hover:bg-amber-400">
                {t('To categorize')}
            </Badge>
        );
    }

    return <span className="text-muted-foreground">—</span>;
}

const reviewLabels: Record<
    NonNullable<RegisterTransaction['reviewReason']>,
    { label: string; hint: string }
> = {
    amount_changed: {
        label: t('Amount changed'),
        hint: t(
            'The bank changed the amount of this transaction: check it, then approve it.',
        ),
    },
    removed_by_bank: {
        label: t('Gone from the bank'),
        hint: t(
            'The bank no longer returns this pending transaction: delete it if it did not happen.',
        ),
    },
    possible_duplicate: {
        label: t('Possible duplicate'),
        hint: t(
            'A transaction typed by hand looks like this one (same account, same amount, close date).',
        ),
    },
};

function PayeeCell({ transaction }: { transaction: RegisterTransaction }) {
    return (
        <span className="inline-flex max-w-full items-center gap-1.5">
            {transaction.transferAccount ? (
                <>
                    <ArrowLeftRight className="size-3.5 shrink-0 text-muted-foreground" />
                    <span className="truncate">
                        {transaction.transferAccount.name}
                    </span>
                </>
            ) : (
                <span
                    className="truncate"
                    title={transaction.rawLabel ?? undefined}
                >
                    {transaction.payee?.name}
                </span>
            )}
            {transaction.cardSuffix && (
                <span
                    className="shrink-0 rounded border px-1 font-mono text-[10px] text-muted-foreground"
                    title={t('Card that paid')}
                >
                    {t('Card *:suffix', { suffix: transaction.cardSuffix })}
                </span>
            )}
            {transaction.bankPending && (
                <span
                    className="shrink-0 text-[10px] text-muted-foreground uppercase"
                    title={t('Not yet posted by the bank')}
                >
                    {t('pending')}
                </span>
            )}
        </span>
    );
}

/**
 * Why the bank sync sent this transaction back, with the possible answers.
 */
function ReviewHint({
    transaction,
    onAction,
}: {
    transaction: RegisterTransaction;
    onAction: (action: typeof merge, transaction: RegisterTransaction) => void;
}) {
    if (!transaction.reviewReason) {
        return null;
    }

    const { label, hint } = reviewLabels[transaction.reviewReason];
    const linkClass =
        'font-medium underline-offset-2 hover:underline focus-visible:underline';

    return (
        <div
            className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-amber-700 dark:text-amber-400"
            onClick={(event) => event.stopPropagation()}
        >
            <span title={hint} className="inline-flex items-center gap-1">
                <AlertTriangle className="size-3" />
                {label}
            </span>
            {transaction.reviewReason === 'possible_duplicate' && (
                <button
                    type="button"
                    className={linkClass}
                    title={t('Merge with the transaction typed by hand')}
                    onClick={() => onAction(merge, transaction)}
                >
                    {t('Match')}
                </button>
            )}
            {transaction.reviewReason !== 'amount_changed' && (
                <button
                    type="button"
                    className={cn(linkClass, 'text-muted-foreground')}
                    onClick={() => onAction(dismissReview, transaction)}
                >
                    {transaction.reviewReason === 'possible_duplicate'
                        ? t('Keep both')
                        : t('Keep')}
                </button>
            )}
        </div>
    );
}

function clearedClass(isCleared: boolean): string {
    return cn(
        'inline-flex size-5 shrink-0 items-center justify-center rounded-full border transition-colors',
        isCleared
            ? 'border-emerald-500 bg-emerald-500 text-white'
            : 'text-transparent hover:text-muted-foreground',
    );
}

/**
 * The transactions of an account (or of every account): inline editing,
 * inbox filter, cleared toggle and approval.
 */
export function TransactionRegister({
    register,
    pickers,
    accountId,
    url,
}: Props) {
    const { currentTeam, inboxCount } = usePage().props;
    const [editing, setEditing] = useState<number | 'new' | null>(null);
    const [selected, setSelected] = useState<number[]>([]);
    const showAccount = accountId === null;
    const columns = showAccount ? 10 : 9;

    if (!currentTeam) {
        return null;
    }

    const unapproved = register.transactions.filter(
        (transaction) => !transaction.approved,
    );

    const approveIds = (ids: number[]) =>
        router.post(
            approve(currentTeam.slug).url,
            { ids },
            { preserveScroll: true, onSuccess: () => setSelected([]) },
        );

    const toggleCleared = (transaction: RegisterTransaction) =>
        router.patch(
            cleared({
                current_team: currentTeam.slug,
                transaction: transaction.id,
            }).url,
            { cleared: !transaction.cleared },
            { preserveScroll: true },
        );

    const reviewAction = (
        action: typeof merge,
        transaction: RegisterTransaction,
    ) =>
        router.post(
            action({
                current_team: currentTeam.slug,
                transaction: transaction.id,
            }).url,
            {},
            { preserveScroll: true },
        );

    const toggleSelected = (id: number) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((candidate) => candidate !== id)
                : [...current, id],
        );

    return (
        <section className="space-y-3">
            {register.balances && (
                <div className="grid grid-cols-3 divide-x rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    {(
                        [
                            [t('Cleared balance'), register.balances.cleared],
                            [t('Uncleared'), register.balances.uncleared],
                            [t('Working balance'), register.balances.working],
                        ] as const
                    ).map(([label, cents]) => (
                        <div
                            key={label}
                            className="min-w-0 px-3 py-2.5 sm:px-4"
                        >
                            <p className="truncate text-xs text-muted-foreground">
                                {label}
                            </p>
                            <Money
                                cents={cents}
                                positive
                                className="text-sm font-semibold sm:text-lg"
                            />
                        </div>
                    ))}
                </div>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    size="sm"
                    onClick={() => setEditing('new')}
                    disabled={!pickers}
                >
                    <Plus />
                    {t('Add transaction')}
                </Button>

                <div className="ml-2 inline-flex rounded-md border p-0.5 text-sm">
                    <Link
                        href={url}
                        preserveScroll
                        className={cn(
                            'rounded px-2.5 py-1',
                            register.filter === null && 'bg-muted font-medium',
                        )}
                    >
                        {t('All transactions')}
                    </Link>
                    <Link
                        href={`${url}?filter=inbox`}
                        preserveScroll
                        className={cn(
                            'rounded px-2.5 py-1',
                            register.filter === 'inbox' &&
                                'bg-muted font-medium',
                        )}
                    >
                        {t('To review')}
                        {inboxCount > 0 && (
                            <Badge className="ml-1.5 h-5 bg-amber-400 px-1.5 text-amber-950 hover:bg-amber-400">
                                {inboxCount}
                            </Badge>
                        )}
                    </Link>
                </div>

                <div className="ml-auto flex gap-2">
                    {selected.length > 0 && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => approveIds(selected)}
                        >
                            <CircleCheck />
                            {t('Approve selection (:count)', {
                                count: selected.length,
                            })}
                        </Button>
                    )}
                    {unapproved.length > 1 && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                approveIds(unapproved.map(({ id }) => id))
                            }
                        >
                            <CheckCheck />
                            {t('Approve all (:count)', {
                                count: unapproved.length,
                            })}
                        </Button>
                    )}
                </div>
            </div>

            <ul className="divide-y divide-sidebar-border/70 overflow-hidden rounded-xl border border-sidebar-border/70 max-sm:-mx-4 max-sm:rounded-none max-sm:border-x-0 md:hidden dark:divide-sidebar-border dark:border-sidebar-border">
                {register.transactions.map((transaction) => (
                    <li
                        key={transaction.id}
                        onClick={() => pickers && setEditing(transaction.id)}
                        className={cn(
                            'flex cursor-pointer items-start gap-3 px-3 py-2.5 active:bg-muted/40',
                            !transaction.approved && 'bg-amber-400/5',
                        )}
                    >
                        <div className="min-w-0 flex-1 text-sm">
                            <div className="font-medium">
                                <PayeeCell transaction={transaction} />
                            </div>
                            <div className="flex items-center gap-1.5 truncate text-xs text-muted-foreground">
                                <span className="tabular-nums">
                                    {formatDate(transaction.date)}
                                </span>
                                <span aria-hidden>·</span>
                                <span className="truncate">
                                    <CategoryCell transaction={transaction} />
                                </span>
                            </div>
                            {showAccount && (
                                <div className="truncate text-xs text-muted-foreground">
                                    {transaction.account.name}
                                </div>
                            )}
                            {transaction.reviewReason && (
                                <ReviewHint
                                    transaction={transaction}
                                    onAction={reviewAction}
                                />
                            )}
                        </div>
                        <div
                            className="flex shrink-0 flex-col items-end gap-1.5"
                            onClick={(event) => event.stopPropagation()}
                        >
                            <Money
                                cents={transaction.amount}
                                positive
                                plain
                                className="text-sm font-medium"
                            />
                            <div className="flex items-center gap-2">
                                {!transaction.approved && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-6 px-2 text-xs"
                                        onClick={() =>
                                            approveIds([transaction.id])
                                        }
                                    >
                                        {t('Approve')}
                                    </Button>
                                )}
                                <button
                                    type="button"
                                    aria-label={
                                        transaction.cleared
                                            ? t('Unclear')
                                            : t('Clear')
                                    }
                                    onClick={() => toggleCleared(transaction)}
                                    className={clearedClass(
                                        transaction.cleared,
                                    )}
                                >
                                    <Check className="size-3" />
                                </button>
                            </div>
                        </div>
                    </li>
                ))}
                {register.transactions.length === 0 && (
                    <li className="px-4 py-10 text-center text-sm text-muted-foreground">
                        {register.filter === 'inbox'
                            ? t(
                                  'Nothing to review: everything is approved and categorized.',
                              )
                            : t('No transactions yet.')}
                    </li>
                )}
            </ul>

            <div className="hidden overflow-x-auto rounded-xl border border-sidebar-border/70 md:block dark:border-sidebar-border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                        <tr>
                            <th className="w-8 px-3 py-2">
                                <span className="sr-only">
                                    {t('Selection')}
                                </span>
                            </th>
                            <th className="px-2 py-2 font-medium">
                                {t('Date')}
                            </th>
                            {showAccount && (
                                <th className="px-2 py-2 font-medium">
                                    {t('Account')}
                                </th>
                            )}
                            <th className="px-2 py-2 font-medium">
                                {t('Payee')}
                            </th>
                            <th className="px-2 py-2 font-medium">
                                {t('Category')}
                            </th>
                            <th className="px-2 py-2 font-medium">
                                {t('Memo')}
                            </th>
                            <th className="px-2 py-2 text-right font-medium">
                                {t('Outflow')}
                            </th>
                            <th className="px-2 py-2 text-right font-medium">
                                {t('Inflow')}
                            </th>
                            <th className="px-2 py-2 text-center font-medium">
                                <span className="sr-only">{t('Cleared')}</span>
                            </th>
                            <th className="px-2 py-2">
                                <span className="sr-only">{t('Approval')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        {register.transactions.map((transaction) => (
                            <tr
                                key={transaction.id}
                                onClick={() =>
                                    pickers && setEditing(transaction.id)
                                }
                                className={cn(
                                    'cursor-pointer hover:bg-muted/40',
                                    !transaction.approved && 'bg-amber-400/5',
                                )}
                            >
                                <td
                                    className="px-3 py-2"
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    <Checkbox
                                        aria-label={t('Select')}
                                        checked={selected.includes(
                                            transaction.id,
                                        )}
                                        onCheckedChange={() =>
                                            toggleSelected(transaction.id)
                                        }
                                    />
                                </td>
                                <td className="px-2 py-2 whitespace-nowrap tabular-nums">
                                    {formatDate(transaction.date)}
                                </td>
                                {showAccount && (
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {transaction.account.name}
                                    </td>
                                )}
                                <td className="max-w-72 px-2 py-2">
                                    <PayeeCell transaction={transaction} />
                                    {transaction.reviewReason && (
                                        <ReviewHint
                                            transaction={transaction}
                                            onAction={reviewAction}
                                        />
                                    )}
                                </td>
                                <td className="max-w-56 truncate px-2 py-2">
                                    <CategoryCell transaction={transaction} />
                                </td>
                                <td className="max-w-56 truncate px-2 py-2 text-muted-foreground">
                                    {transaction.memo}
                                </td>
                                <td className="px-2 py-2 text-right">
                                    {transaction.amount < 0 && (
                                        <Money cents={-transaction.amount} />
                                    )}
                                </td>
                                <td className="px-2 py-2 text-right">
                                    {transaction.amount > 0 && (
                                        <Money cents={transaction.amount} />
                                    )}
                                </td>
                                <td
                                    className="px-2 py-2 text-center"
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    <button
                                        type="button"
                                        aria-label={
                                            transaction.cleared
                                                ? t('Unclear')
                                                : t('Clear')
                                        }
                                        onClick={() =>
                                            toggleCleared(transaction)
                                        }
                                        title={
                                            transaction.cleared
                                                ? t('Cleared')
                                                : t('Not cleared')
                                        }
                                        className={clearedClass(
                                            transaction.cleared,
                                        )}
                                    >
                                        <Check className="size-3" />
                                    </button>
                                </td>
                                <td
                                    className="px-2 py-2 text-right whitespace-nowrap"
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    {transaction.approved ? (
                                        transaction.approvedBy && (
                                            <span
                                                className="text-xs text-muted-foreground"
                                                title={t('Approved by :name', {
                                                    name: transaction.approvedBy,
                                                })}
                                            >
                                                <CircleCheck className="inline size-3.5" />
                                            </span>
                                        )
                                    ) : (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="h-7"
                                            onClick={() =>
                                                approveIds([transaction.id])
                                            }
                                        >
                                            {t('Approve')}
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        ))}

                        {register.transactions.length === 0 && (
                            <tr>
                                <td
                                    colSpan={columns}
                                    className="px-4 py-10 text-center text-muted-foreground"
                                >
                                    {register.filter === 'inbox'
                                        ? t(
                                              'Nothing to review: everything is approved and categorized.',
                                          )
                                        : t('No transactions yet.')}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {pickers && (
                <TransactionEditor
                    key={editing ?? 'closed'}
                    open={editing !== null}
                    transaction={
                        register.transactions.find(
                            (transaction) => transaction.id === editing,
                        ) ?? null
                    }
                    accountId={accountId}
                    pickers={pickers}
                    onDone={() => setEditing(null)}
                />
            )}

            {register.truncated && (
                <p className="text-center text-xs text-muted-foreground">
                    {t('Only the 500 most recent transactions are shown.')}
                </p>
            )}
        </section>
    );
}
