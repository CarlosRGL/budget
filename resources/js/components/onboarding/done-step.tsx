import { Link, usePage } from '@inertiajs/react';
import { Check, UserPlus, Wand2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { t } from '@/lib/i18n';
import { complete } from '@/routes/onboarding';

function Next({
    icon,
    title,
    children,
}: {
    icon: ReactNode;
    title: string;
    children: ReactNode;
}) {
    return (
        <li className="flex gap-4 rounded-2xl border border-sidebar-border/70 bg-card p-4 sm:p-5 dark:border-sidebar-border">
            <div className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-muted text-foreground">
                {icon}
            </div>
            <div className="space-y-1">
                <p className="font-medium">{title}</p>
                <div className="text-sm text-muted-foreground">{children}</div>
            </div>
        </li>
    );
}

/**
 * The household is set up: what to do next.
 */
export function DoneStep() {
    const { currentTeam } = usePage().props;

    return (
        <div className="space-y-6">
            <header className="flex flex-col items-center gap-4 text-center">
                <span className="fx-pop flex size-14 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-700 dark:text-emerald-300">
                    <Check className="size-7" strokeWidth={2.5} />
                </span>
                <div className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                        {t('All set!')}
                    </h1>
                    <p className="mx-auto max-w-md text-pretty text-muted-foreground">
                        {t('Your household is ready. Here is what comes next.')}
                    </p>
                </div>
            </header>

            <ol className="mx-auto grid max-w-lg gap-3">
                <Next
                    icon={<Wand2 className="size-4.5" />}
                    title={t('Assign your Ready to Assign')}
                >
                    {t(
                        'In the budget, share out the money of your accounts between the envelopes, until Ready to Assign reaches zero.',
                    )}
                </Next>
                <Next
                    icon={<UserPlus className="size-4.5" />}
                    title={t('Invite your household')}
                >
                    {t(
                        'Invite a member to share the same budget, with full access or a simple read-only view.',
                    )}{' '}
                    {currentTeam && (
                        <Link
                            href={complete({ current_team: currentTeam.slug })}
                            data={{ to: 'settings' }}
                            as="button"
                            className="font-medium text-foreground underline underline-offset-4"
                        >
                            {t('Household settings')}
                        </Link>
                    )}
                </Next>
            </ol>
        </div>
    );
}
