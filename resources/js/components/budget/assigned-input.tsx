import { useState } from 'react';
import { evaluateMoney, formatMoney, moneyToInput } from '@/lib/money';
import { cn } from '@/lib/utils';

type Props = {
    cents: number;
    onChange: (cents: number) => void;
    label: string;
};

/**
 * The editable "Assigned" amount of a category. Shows `650,00 €`, becomes a plain
 * number while focused and accepts simple sums (`650+20`). Enter or leaving the
 * field saves, Escape cancels.
 */
export function AssignedInput({ cents, onChange, label }: Props) {
    const [draft, setDraft] = useState<string | null>(null);
    const invalid = draft !== null && evaluateMoney(draft) === null;

    const commit = () => {
        if (draft === null) {
            return;
        }

        const value = evaluateMoney(draft);
        setDraft(null);

        if (value !== null && value !== cents) {
            onChange(value);
        }
    };

    return (
        <input
            aria-label={label}
            inputMode="decimal"
            value={draft ?? formatMoney(cents)}
            onFocus={(event) => {
                setDraft(cents === 0 ? '' : moneyToInput(cents));
                requestAnimationFrame(() => event.target.select());
            }}
            onChange={(event) => setDraft(event.target.value)}
            onBlur={commit}
            onClick={(event) => event.stopPropagation()}
            onKeyDown={(event) => {
                if (event.key === 'Enter') {
                    event.currentTarget.blur();
                }

                if (event.key === 'Escape') {
                    setDraft(null);
                    requestAnimationFrame(() => event.currentTarget?.blur());
                }
            }}
            className={cn(
                'h-7 w-full rounded-md border border-transparent bg-transparent px-2 text-right tabular-nums outline-none hover:border-input focus:border-ring focus:bg-background focus:ring-2 focus:ring-ring/30',
                cents === 0 && draft === null && 'text-muted-foreground',
                invalid && 'border-destructive focus:border-destructive',
            )}
        />
    );
}
