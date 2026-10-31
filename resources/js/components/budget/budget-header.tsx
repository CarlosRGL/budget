import { Link } from '@inertiajs/react';
import {
    CalendarCheck,
    ChevronLeft,
    ChevronRight,
    History,
    StickyNote,
    Wand2,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { currentMonth, formatMonth } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import { show } from '@/routes/budget';
import type { BudgetMoneyMove } from '@/types/budget';
import type { BudgetActions } from './budget-actions';
import { MovesHistory } from './moves-history';

type Props = {
    team: string;
    month: string;
    previousMonth: string;
    nextMonth: string;
    readyToAssign: number;
    note: string | null;
    moves: BudgetMoneyMove[];
    actions: BudgetActions;
};

export function BudgetHeader({
    team,
    month,
    previousMonth,
    nextMonth,
    readyToAssign,
    note,
    moves,
    actions,
}: Props) {
    const [draftNote, setDraftNote] = useState(note ?? '');
    const [historyOpen, setHistoryOpen] = useState(false);
    const today = currentMonth();

    return (
        <header className="flex flex-wrap items-center gap-x-2 gap-y-2 sm:gap-x-6 sm:gap-y-3">
            <div className="flex min-w-0 items-center gap-1">
                <Button variant="ghost" size="icon" asChild>
                    <Link
                        href={show({
                            current_team: team,
                            month: previousMonth,
                        })}
                        aria-label={t('Previous month')}
                        preserveScroll
                    >
                        <ChevronLeft />
                    </Link>
                </Button>
                <h1 className="text-center text-xl font-semibold first-letter:uppercase sm:min-w-44 sm:text-2xl">
                    {formatMonth(month)}
                </h1>
                <Button variant="ghost" size="icon" asChild>
                    <Link
                        href={show({ current_team: team, month: nextMonth })}
                        aria-label={t('Next month')}
                        preserveScroll
                    >
                        <ChevronRight />
                    </Link>
                </Button>
                {month !== today && (
                    <Button
                        variant="link"
                        size="sm"
                        className="max-sm:px-2"
                        asChild
                    >
                        <Link href={show({ current_team: team, month: today })}>
                            <CalendarCheck className="sm:hidden" />
                            <span className="max-sm:sr-only">{t('Today')}</span>
                        </Link>
                    </Button>
                )}
            </div>

            <div className="ml-auto flex items-center sm:ml-0 sm:gap-1">
                <Popover
                    onOpenChange={(open) => {
                        if (open) {
                            setDraftNote(note ?? '');
                        } else if (draftNote !== (note ?? '')) {
                            actions.saveMonthNote(draftNote);
                        }
                    }}
                >
                    <PopoverTrigger asChild>
                        <Button
                            variant="ghost"
                            size="sm"
                            className={cn(
                                'max-w-64 text-muted-foreground',
                                note && 'text-foreground',
                            )}
                        >
                            <StickyNote />
                            <span className="truncate max-sm:sr-only">
                                {note ?? t('Month note')}
                            </span>
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent align="start" className="w-80">
                        <textarea
                            aria-label={t('Month note')}
                            autoFocus
                            rows={4}
                            value={draftNote}
                            onChange={(event) =>
                                setDraftNote(event.target.value)
                            }
                            placeholder={t(
                                'E.g. year-end bonus, Camille’s birthday…',
                            )}
                            className="w-full resize-none rounded-md border bg-background p-2 text-sm outline-none focus:ring-2 focus:ring-ring/30"
                        />
                        <p className="mt-1 text-xs text-muted-foreground">
                            {t('Saved when closed.')}
                        </p>
                    </PopoverContent>
                </Popover>
                <Button
                    variant="ghost"
                    size="sm"
                    className="text-muted-foreground"
                    onClick={() => setHistoryOpen(true)}
                >
                    <History />
                    <span className="max-sm:sr-only">{t('History')}</span>
                </Button>
                <MovesHistory
                    open={historyOpen}
                    onOpenChange={setHistoryOpen}
                    month={month}
                    moves={moves}
                />
            </div>

            <div
                className={cn(
                    'flex w-full items-center gap-4 rounded-xl px-3 py-2 ring-1 ring-inset sm:ml-auto sm:w-auto sm:px-4 sm:py-2.5',
                    readyToAssign > 0 &&
                        'bg-emerald-500/10 ring-emerald-500/30',
                    readyToAssign < 0 && 'bg-red-500/10 ring-red-500/40',
                    readyToAssign === 0 && 'bg-muted/50 ring-border',
                )}
            >
                <div className="mr-auto">
                    <p className="text-xs font-medium text-muted-foreground">
                        {readyToAssign < 0
                            ? t('Over-assigned: take some back')
                            : t('Ready to Assign')}
                    </p>
                    <p
                        className={cn(
                            'text-xl leading-tight font-semibold tabular-nums',
                            readyToAssign > 0 &&
                                'text-emerald-700 dark:text-emerald-300',
                            readyToAssign < 0 &&
                                'text-red-700 dark:text-red-300',
                        )}
                    >
                        {formatMoney(readyToAssign)}
                    </p>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button size="sm" variant="outline">
                            <Wand2 />
                            {t('Assign')}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-64">
                        <DropdownMenuLabel>
                            {t('Assign this month')}
                        </DropdownMenuLabel>
                        <DropdownMenuItem
                            onSelect={() => actions.autoAssign('underfunded')}
                        >
                            {t('Fund underfunded targets')}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={() => actions.autoAssign('last_month')}
                        >
                            {t('Same as last month')}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={() => actions.autoAssign('average')}
                        >
                            {t('Average assigned')}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => actions.autoAssign('reset')}
                        >
                            {t('Reset everything to zero')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}
