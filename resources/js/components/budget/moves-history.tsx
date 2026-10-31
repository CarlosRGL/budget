import { ArrowRight } from 'lucide-react';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatMonth } from '@/lib/dates';
import { intlLocale, t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import type { BudgetMoneyMove } from '@/types/budget';

const kindLabels: Record<BudgetMoneyMove['kind'], string> = {
    assign: t('Assignment'),
    move: t('Move'),
    auto_assign: t('Automatic assignment'),
    reset: t('Reset to zero'),
};

const dateTime = new Intl.DateTimeFormat(intlLocale(), {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    month: string;
    moves: BudgetMoneyMove[];
};

/**
 * Money assigned and moved during the month: who, when, from where to where.
 */
export function MovesHistory({ open, onOpenChange, month, moves }: Props) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>{t('Money move history')}</SheetTitle>
                    <SheetDescription>{formatMonth(month)}</SheetDescription>
                </SheetHeader>
                {moves.length === 0 ? (
                    <p className="px-4 text-sm text-muted-foreground">
                        {t('No moves this month.')}
                    </p>
                ) : (
                    <ol className="divide-y px-4 pb-6">
                        {moves.map((move) => (
                            <li key={move.id} className="space-y-1 py-3">
                                <div className="flex items-center gap-2 text-sm">
                                    <span className="truncate">
                                        {move.from?.name ??
                                            t('Ready to Assign')}
                                    </span>
                                    <ArrowRight className="size-3.5 shrink-0 text-muted-foreground" />
                                    <span className="truncate">
                                        {move.to?.name ?? t('Ready to Assign')}
                                    </span>
                                    <span className="ml-auto font-medium tabular-nums">
                                        {formatMoney(move.amount)}
                                    </span>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {move.user
                                        ? t(':kind by :user', {
                                              kind: kindLabels[move.kind],
                                              user: move.user,
                                          })
                                        : kindLabels[move.kind]}
                                    {' · '}
                                    {dateTime.format(new Date(move.at))}
                                </p>
                            </li>
                        ))}
                    </ol>
                )}
            </SheetContent>
        </Sheet>
    );
}
