import { cn } from '@/lib/utils';

type Props = {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    label: string;
};

/**
 * A small on/off switch.
 */
export function Switch({ checked, onCheckedChange, label }: Props) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            onClick={() => onCheckedChange(!checked)}
            className={cn(
                'relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none',
                checked ? 'bg-primary' : 'bg-muted-foreground/30',
            )}
        >
            <span
                className={cn(
                    'inline-block size-4 rounded-full bg-background shadow transition-transform',
                    checked ? 'translate-x-4.5' : 'translate-x-0.5',
                )}
            />
        </button>
    );
}
