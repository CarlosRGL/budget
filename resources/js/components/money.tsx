import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

type MoneyProps = {
    cents: number;
    /** Colour positive amounts in green. */
    positive?: boolean;
    /** No colour at all, even for negative amounts (spending columns). */
    plain?: boolean;
    className?: string;
};

export function Money({
    cents,
    positive = false,
    plain = false,
    className,
}: MoneyProps) {
    return (
        <span
            className={cn(
                'whitespace-nowrap tabular-nums',
                !plain && cents < 0 && 'text-red-600 dark:text-red-400',
                positive &&
                    cents > 0 &&
                    'text-emerald-600 dark:text-emerald-400',
                className,
            )}
        >
            {formatMoney(cents)}
        </span>
    );
}
