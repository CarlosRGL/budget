import { Check } from 'lucide-react';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type OnboardingStep = 'welcome' | 'categories' | 'accounts' | 'done';

export const steps: OnboardingStep[] = [
    'welcome',
    'categories',
    'accounts',
    'done',
];

export function stepLabel(step: OnboardingStep): string {
    return {
        welcome: t('How it works'),
        categories: t('Categories'),
        accounts: t('Accounts'),
        done: t('Finished'),
    }[step];
}

/**
 * Where the member is in the setup: dots joined by a line that fills up.
 * Earlier steps can be clicked to go back.
 */
export function Stepper({
    current,
    onSelect,
}: {
    current: OnboardingStep;
    onSelect: (step: OnboardingStep) => void;
}) {
    const index = steps.indexOf(current);

    return (
        <nav aria-label={t('Setup steps')} className="w-full">
            <p className="mb-3 text-center text-xs font-medium text-muted-foreground sm:hidden">
                {t('Step :current of :total', {
                    current: index + 1,
                    total: steps.length,
                })}
                {' · '}
                {stepLabel(current)}
            </p>
            <ol className="flex items-center">
                {steps.map((step, position) => {
                    const done = position < index;
                    const active = position === index;

                    return (
                        <li
                            key={step}
                            className={cn(
                                'flex items-center',
                                position > 0 && 'flex-1',
                            )}
                        >
                            {position > 0 && (
                                <span className="mx-2 h-0.5 flex-1 overflow-hidden rounded-full bg-border">
                                    <span
                                        className="block h-full origin-left bg-primary transition-transform duration-500 ease-[var(--ease-out-quint)]"
                                        style={{
                                            transform: `scaleX(${position <= index ? 1 : 0})`,
                                        }}
                                    />
                                </span>
                            )}
                            <button
                                type="button"
                                disabled={!done}
                                onClick={() => onSelect(step)}
                                aria-current={active ? 'step' : undefined}
                                className="group flex items-center gap-2 rounded-full outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-default"
                            >
                                <span
                                    className={cn(
                                        'flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-colors duration-300',
                                        done &&
                                            'bg-primary text-primary-foreground group-hover:bg-primary/85',
                                        active &&
                                            'bg-background text-foreground ring-2 ring-primary',
                                        !done &&
                                            !active &&
                                            'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {done ? (
                                        <Check className="fx-pop size-3.5" />
                                    ) : (
                                        position + 1
                                    )}
                                </span>
                                <span
                                    className={cn(
                                        'text-sm whitespace-nowrap max-sm:sr-only',
                                        active
                                            ? 'font-medium text-foreground'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {stepLabel(step)}
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
