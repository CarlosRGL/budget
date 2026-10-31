import { Pencil, Target, X } from 'lucide-react';
import { useState } from 'react';
import { Money } from '@/components/money';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { intlLocale, t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type {
    BudgetCategory,
    BudgetCategoryDetail,
    BudgetTarget,
} from '@/types/budget';
import type { BudgetActions } from './budget-actions';
import { AssignedInput } from './assigned-input';
import { pillClasses } from './available-pill';
import { Switch } from './switch';
import { TargetForm, weekdayName } from './target-form';

type Props = {
    category: BudgetCategory;
    groupName: string;
    /** Last month and averages; null while loading. */
    detail: BudgetCategoryDetail | null;
    actions: BudgetActions;
    onClose: () => void;
    /** Inside a sheet: no frame nor close button of its own. */
    bare?: boolean;
};

const longDate = new Intl.DateTimeFormat(intlLocale(), {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

export function targetLabel(target: BudgetTarget): string {
    const amount = formatMoney(target.amount);

    switch (target.type) {
        case 'refill_up_to':
            return t('Refill up to :amount each month', { amount });
        case 'set_aside':
            return t('Set aside :amount each month', { amount });
        case 'weekly':
            return target.weekday
                ? t(':amount each week, on :weekday', {
                      amount,
                      weekday: weekdayName(target.weekday),
                  })
                : t(':amount each week', { amount });
        case 'by_date': {
            const date = target.dueDate
                ? longDate.format(new Date(`${target.dueDate}T12:00:00`))
                : '…';

            return target.repeat === 'yearly'
                ? t(':amount by :date, every year', { amount, date })
                : t(':amount by :date', { amount, date });
        }
    }
}

function Row({
    label,
    cents,
    strong = false,
}: {
    label: string;
    cents: number;
    strong?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex items-center justify-between gap-4 py-1',
                strong && 'border-t pt-2 font-semibold',
            )}
        >
            <span className={cn(!strong && 'text-muted-foreground')}>
                {label}
            </span>
            <Money cents={cents} />
        </div>
    );
}

/**
 * The selected category: where its money comes from, its target, quick
 * assignment amounts and a note (design §5.5).
 */
export function CategoryDetail({
    category,
    groupName,
    detail,
    actions,
    onClose,
    bare = false,
}: Props) {
    const [note, setNote] = useState(category.note ?? '');
    const [editingTarget, setEditingTarget] = useState(false);
    const target = category.target;

    const shortcuts: { label: string; assigned: number | null }[] = [
        {
            label: t('Assigned last month'),
            assigned: detail?.assignedLastMonth ?? null,
        },
        {
            label: t('Spent last month'),
            assigned: detail?.spentLastMonth ?? null,
        },
        {
            label: t('Average assigned'),
            assigned: detail?.averageAssigned ?? null,
        },
        { label: t('Average spent'), assigned: detail?.averageSpent ?? null },
        {
            label: t('Reset available to 0'),
            assigned: category.assigned - category.available,
        },
        { label: t('Reset assigned to 0'), assigned: 0 },
    ];

    return (
        <aside
            className={cn(
                'space-y-5 text-sm',
                !bare &&
                    'rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="text-xs text-muted-foreground">{groupName}</p>
                    <h2 className="truncate text-lg font-semibold">
                        {category.name}
                    </h2>
                </div>
                {!bare && (
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        onClick={onClose}
                        aria-label={t('Close the detail')}
                    >
                        <X />
                    </Button>
                )}
            </div>

            <section>
                <div
                    className={cn(
                        'mb-3 flex items-center justify-between rounded-lg px-3 py-2',
                        pillClasses(category),
                    )}
                >
                    <span className="font-medium">{t('Available')}</span>
                    <span className="text-lg font-semibold tabular-nums">
                        {formatMoney(category.available)}
                    </span>
                </div>
                <Row
                    label={t('Left over from last month')}
                    cents={category.carryover}
                />
                <div className="flex items-center justify-between gap-4 py-0.5">
                    <span className="text-muted-foreground">
                        {t('Assigned this month')}
                    </span>
                    <span className="w-32">
                        <AssignedInput
                            cents={category.assigned}
                            label={t('Assigned to :category', {
                                category: category.name,
                            })}
                            onChange={(cents) =>
                                actions.assign(category.id, cents)
                            }
                        />
                    </span>
                </div>
                <Row label={t('Activity')} cents={category.activity} />
                <Row label={t('Available')} cents={category.available} strong />
            </section>

            <section className="space-y-2">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="font-medium">{t('Target')}</h3>
                    {target && !editingTarget && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7"
                            onClick={() => setEditingTarget(true)}
                        >
                            <Pencil />
                            {t('Edit')}
                        </Button>
                    )}
                </div>
                {editingTarget ? (
                    <TargetForm
                        target={target}
                        onCancel={() => setEditingTarget(false)}
                        onSave={(data) =>
                            actions.saveTarget(category.id, data, () =>
                                setEditingTarget(false),
                            )
                        }
                        onDelete={
                            target
                                ? () =>
                                      actions.deleteTarget(category.id, () =>
                                          setEditingTarget(false),
                                      )
                                : undefined
                        }
                    />
                ) : target ? (
                    <div className="space-y-2">
                        <p>{targetLabel(target)}</p>
                        <div className="h-2 overflow-hidden rounded-full bg-muted">
                            <div
                                className={cn(
                                    'fx-grow h-full rounded-full',
                                    target.snoozed
                                        ? 'bg-muted-foreground/40'
                                        : target.ratio >= 1
                                          ? 'bg-emerald-500'
                                          : 'bg-amber-400',
                                )}
                                style={{
                                    width: `${Math.min(1, Math.max(0, target.ratio)) * 100}%`,
                                }}
                            />
                        </div>
                        {!target.snoozed && (
                            <>
                                <Row
                                    label={t('Needed this month')}
                                    cents={target.needed}
                                />
                                <Row
                                    label={t('Funded')}
                                    cents={target.funded}
                                />
                                <Row
                                    label={t('Left to fund')}
                                    cents={target.toGo}
                                />
                            </>
                        )}
                        {target.underfunded && !target.snoozed && (
                            <Button
                                size="sm"
                                className="w-full"
                                onClick={() =>
                                    actions.assign(
                                        category.id,
                                        category.assigned + target.toGo,
                                    )
                                }
                            >
                                {t('Fund :amount', {
                                    amount: formatMoney(target.toGo),
                                })}
                            </Button>
                        )}
                        <label className="flex items-center justify-between gap-3 pt-1">
                            <span>
                                {t('Snooze this month')}
                                <span className="block text-xs text-muted-foreground">
                                    {t(
                                        'Ignored by “Fund targets” and the filters.',
                                    )}
                                </span>
                            </span>
                            <Switch
                                checked={target.snoozed}
                                onCheckedChange={(snoozed) =>
                                    actions.snooze(category.id, snoozed)
                                }
                                label={t('Snooze the target this month')}
                            />
                        </label>
                    </div>
                ) : (
                    <div className="space-y-2">
                        <p className="text-muted-foreground">
                            {t('No target for this category.')}
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setEditingTarget(true)}
                        >
                            <Target />
                            {t('Add a target')}
                        </Button>
                    </div>
                )}
            </section>

            <section className="space-y-1">
                <h3 className="mb-2 font-medium">{t('Quick assign')}</h3>
                {shortcuts.map((shortcut) => (
                    <button
                        key={shortcut.label}
                        type="button"
                        disabled={
                            shortcut.assigned === null ||
                            shortcut.assigned === category.assigned
                        }
                        onClick={() =>
                            shortcut.assigned !== null &&
                            actions.assign(category.id, shortcut.assigned)
                        }
                        className="flex w-full items-center justify-between gap-4 rounded-md px-2 py-1.5 text-left hover:bg-muted disabled:pointer-events-none disabled:opacity-50"
                    >
                        <span>{shortcut.label}</span>
                        {shortcut.assigned === null ? (
                            <Skeleton className="h-4 w-16" />
                        ) : (
                            <span className="tabular-nums">
                                {formatMoney(shortcut.assigned)}
                            </span>
                        )}
                    </button>
                ))}
            </section>

            <section className="space-y-2">
                <h3 className="font-medium">{t('Note')}</h3>
                <textarea
                    aria-label={t('Note for :category', {
                        category: category.name,
                    })}
                    rows={3}
                    value={note}
                    onChange={(event) => setNote(event.target.value)}
                    onBlur={() => {
                        if (note !== (category.note ?? '')) {
                            actions.updateCategory(category.id, {
                                note: note.trim() === '' ? null : note,
                            });
                        }
                    }}
                    placeholder={t('Add a note…')}
                    className="w-full resize-none rounded-md border bg-background p-2 outline-none focus:ring-2 focus:ring-ring/30"
                />
            </section>
        </aside>
    );
}
