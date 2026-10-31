import { ChevronDown, Pause, Play, RotateCcw, Target, X } from 'lucide-react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { t, tc } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import type { BudgetCategory } from '@/types/budget';
import type { AutoAssignStrategy, BudgetActions } from './budget-actions';

/**
 * Actions on the ticked categories of the month, floating above the table
 * (and above the tab bar on phones).
 */
export function BulkBar({
    categories,
    actions,
    onClear,
}: {
    categories: BudgetCategory[];
    actions: BudgetActions;
    onClear: () => void;
}) {
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClear();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [onClear]);

    if (categories.length === 0) {
        return null;
    }

    const ids = categories.map((category) => category.id);
    const assigned = categories.reduce(
        (sum, category) => sum + category.assigned,
        0,
    );
    const run = (strategy: AutoAssignStrategy) =>
        actions.autoAssign(strategy, ids, onClear);

    // Only categories with a target can be paused; resume when they all are.
    const targeted = categories.filter((category) => category.target);
    const allPaused =
        targeted.length > 0 &&
        targeted.every((category) => category.target?.snoozed);

    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-40 flex justify-center px-3 md:bottom-6">
            <div
                role="toolbar"
                aria-label={t('Actions on the selection')}
                className="pointer-events-auto flex flex-wrap items-center gap-1.5 rounded-xl border bg-popover/95 p-1.5 pl-3 text-sm shadow-lg backdrop-blur"
            >
                <span className="mr-1 tabular-nums">
                    <span className="font-medium">
                        {tc(
                            ':count category|:count categories',
                            categories.length,
                        )}
                    </span>
                    <span className="text-muted-foreground">
                        {' · '}
                        {t(':amount assigned', {
                            amount: formatMoney(assigned),
                        })}
                    </span>
                </span>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={assigned === 0}
                    onClick={() => run('reset')}
                >
                    <RotateCcw />
                    {t('Reset to 0')}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => run('underfunded')}
                >
                    <Target />
                    {t('Fund targets')}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={targeted.length === 0}
                    title={
                        targeted.length === 0
                            ? t('No target in the selection')
                            : undefined
                    }
                    onClick={() =>
                        actions.snoozeMany(
                            targeted.map((category) => category.id),
                            !allPaused,
                            onClear,
                        )
                    }
                >
                    {allPaused ? <Play /> : <Pause />}
                    {allPaused ? t('Resume') : t('Snooze')}
                </Button>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button size="sm" variant="outline">
                            {t('More')}
                            <ChevronDown className="opacity-60" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={() => run('last_month')}>
                            {t('Same as last month')}
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={() => run('average')}>
                            {t('Average assigned')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-8"
                    aria-label={t('Clear selection')}
                    onClick={onClear}
                >
                    <X />
                </Button>
            </div>
        </div>
    );
}
