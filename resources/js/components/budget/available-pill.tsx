import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { t } from '@/lib/i18n';
import { evaluateMoney, formatMoney, moneyToInput } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { BudgetCategory, BudgetGroup } from '@/types/budget';
import type { BudgetActions } from './budget-actions';

type Props = {
    category: BudgetCategory;
    groups: BudgetGroup[];
    readyToAssign: number;
    actions: BudgetActions;
};

/**
 * Colour of the "Available" pill (design §5.5): red when overspent, yellow while
 * a target still needs money, green when there is money, grey at zero.
 */
export function pillClasses(category: BudgetCategory): string {
    if (category.available < 0) {
        return 'ring-1 ring-inset bg-red-500/15 text-red-700 ring-red-500/40 dark:text-red-300';
    }

    if (category.target?.underfunded) {
        return 'ring-1 ring-inset bg-amber-400/15 text-amber-700 ring-amber-500/40 dark:text-amber-300';
    }

    if (category.available > 0) {
        return 'ring-1 ring-inset bg-emerald-500/15 text-emerald-700 ring-emerald-500/30 dark:text-emerald-300';
    }

    return 'ring-1 ring-inset bg-muted/60 text-muted-foreground ring-border';
}

/**
 * The "Available" pill. Clicking it moves money: out of the category when it
 * has some, or into it from elsewhere to cover an overspending.
 */
export function AvailablePill({
    category,
    groups,
    readyToAssign,
    actions,
}: Props) {
    const [open, setOpen] = useState(false);
    const covering = category.available < 0;
    const [amount, setAmount] = useState('');
    const cents = evaluateMoney(amount);

    const reset = (next: boolean) => {
        setOpen(next);

        if (next) {
            setAmount(
                category.available === 0
                    ? ''
                    : moneyToInput(Math.abs(category.available)),
            );
        }
    };

    const send = (otherCategoryId: number | null) => {
        if (cents === null || cents <= 0) {
            return;
        }

        const [from, to] = covering
            ? [otherCategoryId, category.id]
            : [category.id, otherCategoryId];

        actions.move(from, to, cents, () => setOpen(false));
    };

    const progress =
        category.target && !category.target.snoozed && category.available >= 0
            ? Math.min(1, Math.max(0, category.target.ratio))
            : null;

    return (
        <Popover open={open} onOpenChange={reset}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    onClick={(event) => event.stopPropagation()}
                    aria-label={t('Move money from :category', {
                        category: category.name,
                    })}
                    className={cn(
                        'relative inline-flex min-w-20 items-center justify-end overflow-hidden rounded-full px-2.5 py-0.5 text-sm font-medium tabular-nums transition hover:brightness-110',
                        pillClasses(category),
                    )}
                >
                    {progress !== null && progress < 1 && (
                        <span
                            aria-hidden
                            className="fx-grow absolute inset-y-0 left-0 bg-current opacity-15"
                            style={{ width: `${progress * 100}%` }}
                        />
                    )}
                    <span className="relative">
                        {formatMoney(category.available)}
                    </span>
                </button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                className="w-80 p-0"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="space-y-2 border-b p-3">
                    <p className="text-sm font-medium">
                        {covering
                            ? t('Cover the overspending of :category', {
                                  category: category.name,
                              })
                            : t('Move from :category', {
                                  category: category.name,
                              })}
                    </p>
                    <input
                        aria-label={t('Amount to move')}
                        inputMode="decimal"
                        autoFocus
                        value={amount}
                        onChange={(event) => setAmount(event.target.value)}
                        onFocus={(event) => event.target.select()}
                        className={cn(
                            'h-9 w-full rounded-md border bg-background px-3 text-right tabular-nums outline-none focus:ring-2 focus:ring-ring/30',
                            amount !== '' &&
                                (cents === null || cents <= 0) &&
                                'border-destructive',
                        )}
                    />
                    <p className="text-xs text-muted-foreground">
                        {covering ? t('Take the money from:') : t('To:')}
                    </p>
                </div>
                <Command>
                    <CommandInput placeholder={t('Search for a category…')} />
                    <CommandList className="max-h-64">
                        <CommandEmpty>{t('No category.')}</CommandEmpty>
                        <CommandGroup>
                            <CommandItem
                                value="pret-a-affecter"
                                keywords={[t('Ready to Assign')]}
                                onSelect={() => send(null)}
                            >
                                <span className="flex-1">
                                    {t('Ready to Assign')}
                                </span>
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    {formatMoney(readyToAssign)}
                                </span>
                            </CommandItem>
                        </CommandGroup>
                        {groups.map((group) => (
                            <CommandGroup key={group.id} heading={group.name}>
                                {group.categories
                                    .filter(
                                        (other) =>
                                            other.id !== category.id &&
                                            !other.hidden,
                                    )
                                    .map((other) => (
                                        <CommandItem
                                            key={other.id}
                                            value={`category-${other.id}`}
                                            keywords={[other.name, group.name]}
                                            onSelect={() => send(other.id)}
                                        >
                                            <span className="flex-1 truncate">
                                                {other.name}
                                            </span>
                                            <span
                                                className={cn(
                                                    'text-xs tabular-nums',
                                                    other.available < 0
                                                        ? 'text-red-400'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {formatMoney(other.available)}
                                            </span>
                                        </CommandItem>
                                    ))}
                            </CommandGroup>
                        ))}
                    </CommandList>
                </Command>
                <div className="flex justify-end border-t p-2">
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setOpen(false)}
                    >
                        {t('Cancel')}
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
