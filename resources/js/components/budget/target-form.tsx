import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { intlLocale, t } from '@/lib/i18n';
import { evaluateMoney, moneyToInput } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { BudgetTarget } from '@/types/budget';
import type { TargetData } from './budget-actions';

type TargetType = BudgetTarget['type'];

const types: { value: TargetType; label: string; hint: string }[] = [
    {
        value: 'refill_up_to',
        label: t('Refill up to'),
        hint: t(
            'Each month, the envelope goes back up to this amount (e.g. Groceries €650). What is left counts for the next month.',
        ),
    },
    {
        value: 'set_aside',
        label: t('Set aside'),
        hint: t(
            'This amount is added every month and builds up (e.g. Holidays €200).',
        ),
    },
    {
        value: 'weekly',
        label: t('Weekly'),
        hint: t(
            'This amount for each week: the month’s need follows how many times that day falls in the month.',
        ),
    },
    {
        value: 'by_date',
        label: t('By date'),
        hint: t(
            'Save up this amount for a due date (e.g. car insurance in March), spread over the remaining months.',
        ),
    },
];

const weekdayFormat = new Intl.DateTimeFormat(intlLocale(), {
    weekday: 'long',
});

/**
 * The name of a weekday in the member's language, 1 = Monday to 7 = Sunday
 * (1 January 2024 was a Monday).
 */
export function weekdayName(weekday: number): string {
    return weekdayFormat.format(new Date(2024, 0, weekday, 12));
}

const weekdays = [1, 2, 3, 4, 5, 6, 7].map((weekday) => {
    const name = weekdayName(weekday);

    return name.charAt(0).toLocaleUpperCase() + name.slice(1);
});

type Props = {
    target: BudgetTarget | null;
    onSave: (data: TargetData) => void;
    onDelete?: () => void;
    onCancel: () => void;
};

/**
 * Create or change the target of a category (design §5.6).
 */
export function TargetForm({ target, onSave, onDelete, onCancel }: Props) {
    const [type, setType] = useState<TargetType>(
        target?.type ?? 'refill_up_to',
    );
    const [amount, setAmount] = useState(
        target ? moneyToInput(target.amount) : '',
    );
    const [weekday, setWeekday] = useState(String(target?.weekday ?? 6));
    const [dueDate, setDueDate] = useState(target?.dueDate ?? '');
    const [yearly, setYearly] = useState(target?.repeat === 'yearly');

    const cents = evaluateMoney(amount);
    const valid =
        cents !== null && cents > 0 && (type !== 'by_date' || dueDate !== '');

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!valid || cents === null) {
            return;
        }

        onSave({
            type,
            amount: cents,
            weekday: type === 'weekly' ? Number(weekday) : null,
            due_date: type === 'by_date' ? dueDate : null,
            repeat: type === 'by_date' && yearly ? 'yearly' : null,
        });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1">
                {types.map((candidate) => (
                    <button
                        key={candidate.value}
                        type="button"
                        onClick={() => setType(candidate.value)}
                        aria-pressed={type === candidate.value}
                        className={cn(
                            'rounded-md px-2 py-1.5 text-xs font-medium text-muted-foreground transition',
                            type === candidate.value &&
                                'bg-background text-foreground shadow-sm',
                        )}
                    >
                        {candidate.label}
                    </button>
                ))}
            </div>
            <p className="text-xs text-muted-foreground">
                {types.find((candidate) => candidate.value === type)?.hint}
            </p>

            <div className="grid gap-1.5">
                <Label htmlFor="target-amount">
                    {type === 'weekly' ? t('Amount per week') : t('Amount')}
                </Label>
                <Input
                    id="target-amount"
                    inputMode="decimal"
                    autoFocus
                    placeholder={moneyToInput(0)}
                    className="text-right tabular-nums"
                    value={amount}
                    onChange={(event) => setAmount(event.target.value)}
                />
            </div>

            {type === 'weekly' && (
                <div className="grid gap-1.5">
                    <Label>{t('Day')}</Label>
                    <Select value={weekday} onValueChange={setWeekday}>
                        <SelectTrigger className="w-full" aria-label={t('Day')}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {weekdays.map((day, index) => (
                                <SelectItem key={day} value={String(index + 1)}>
                                    {day}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            )}

            {type === 'by_date' && (
                <>
                    <div className="grid gap-1.5">
                        <Label htmlFor="target-due-date">{t('Due date')}</Label>
                        <Input
                            id="target-due-date"
                            type="date"
                            value={dueDate}
                            onChange={(event) => setDueDate(event.target.value)}
                        />
                    </div>
                    <label className="flex w-fit items-center gap-2">
                        <Checkbox
                            checked={yearly}
                            onCheckedChange={(checked) =>
                                setYearly(checked === true)
                            }
                        />
                        {t('Repeat every year')}
                    </label>
                </>
            )}

            <div className="flex items-center justify-between gap-2 pt-1">
                {onDelete ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-red-600 dark:text-red-400"
                        onClick={onDelete}
                    >
                        {t('Delete')}
                    </Button>
                ) : (
                    <span />
                )}
                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onCancel}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" size="sm" disabled={!valid}>
                        {t('Save')}
                    </Button>
                </div>
            </div>
        </form>
    );
}
