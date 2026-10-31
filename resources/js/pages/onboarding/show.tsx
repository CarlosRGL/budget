import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { AccountsStep } from '@/components/onboarding/accounts-step';
import type {
    DraftGroup,
    ExistingGroup,
} from '@/components/onboarding/categories-step';
import {
    CategoriesStep,
    draftFromTemplate,
    selectedGroups,
} from '@/components/onboarding/categories-step';
import { DoneStep } from '@/components/onboarding/done-step';
import type { OnboardingStep } from '@/components/onboarding/stepper';
import { Stepper, steps } from '@/components/onboarding/stepper';
import { WelcomeStep } from '@/components/onboarding/welcome-step';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { categories, complete, show } from '@/routes/onboarding';

type Props = {
    step: OnboardingStep;
    starterGroups: { name: string; categories: string[] }[];
    existingGroups: ExistingGroup[];
    bankingConfigured: boolean;
};

/**
 * First-run setup of a household, on its own page without the app's sidebar:
 * how it works, the categories, the accounts, then the budget.
 */
export default function Onboarding({
    step,
    starterGroups,
    existingGroups,
    bankingConfigured,
}: Props) {
    const { currentTeam, errors } = usePage().props;
    const [draft, setDraft] = useState<DraftGroup[]>(() =>
        draftFromTemplate(starterGroups),
    );
    const [processing, setProcessing] = useState(false);

    if (!currentTeam) {
        return null;
    }

    const team = { current_team: currentTeam.slug };
    const index = steps.indexOf(step);
    const selected = selectedGroups(draft);
    const hasCategories = existingGroups.length > 0;

    // Steps change on the client; the URL follows so a reload (or coming
    // back from the bank connection) lands on the same step.
    const goTo = (next: OnboardingStep) =>
        router.replace({
            url: show.url(team, { query: { step: next } }),
            props: (props) => ({ ...props, step: next }),
        });

    const finish = () =>
        router.post(
            complete(team).url,
            {},
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    const saveCategories = () =>
        router.post(
            categories(team).url,
            { groups: selected },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    const next = () => {
        if (step === 'categories' && !hasCategories) {
            saveCategories();
        } else if (step === 'done') {
            finish();
        } else {
            goTo(steps[index + 1]);
        }
    };

    const categoryError =
        errors.groups ??
        Object.entries(errors).find(([key]) => key.startsWith('groups.'))?.[1];

    return (
        <>
            <Head title={t('Set up your household')} />
            <div className="flex min-h-svh flex-col bg-background">
                <header className="mx-auto flex w-full max-w-3xl items-center gap-3 px-4 pt-4 sm:px-6 sm:pt-6">
                    <AppLogoIcon className="size-8" />
                    <span className="truncate font-medium">
                        {currentTeam.name}
                    </span>
                    <Link
                        href={complete(team)}
                        as="button"
                        className="ml-auto shrink-0 rounded-md px-2 py-1 text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                        {t('Skip setup')}
                    </Link>
                </header>

                <div className="mx-auto w-full max-w-xl px-4 pt-6 sm:px-6 sm:pt-10">
                    <Stepper current={step} onSelect={goTo} />
                </div>

                <main className="mx-auto w-full max-w-3xl flex-1 px-4 pt-8 pb-32 sm:px-6 sm:pt-10">
                    <div key={step} className="fx-route">
                        {step === 'welcome' && (
                            <WelcomeStep household={currentTeam.name} />
                        )}
                        {step === 'categories' && (
                            <CategoriesStep
                                draft={draft}
                                onChange={setDraft}
                                existingGroups={existingGroups}
                                error={categoryError}
                            />
                        )}
                        {step === 'accounts' && (
                            <AccountsStep
                                bankingConfigured={bankingConfigured}
                            />
                        )}
                        {step === 'done' && <DoneStep />}
                    </div>
                </main>

                {/* Kept in reach of the thumb on phones. */}
                <footer className="fixed inset-x-0 bottom-0 border-t bg-background/85 pb-[env(safe-area-inset-bottom)] backdrop-blur supports-[backdrop-filter]:bg-background/70">
                    <div className="mx-auto flex w-full max-w-3xl items-center gap-3 px-4 py-3 sm:px-6">
                        {index > 0 && step !== 'done' && (
                            <Button
                                variant="ghost"
                                onClick={() => goTo(steps[index - 1])}
                            >
                                <ArrowLeft />
                                {t('Back')}
                            </Button>
                        )}
                        <Button
                            size="lg"
                            className="ml-auto max-sm:flex-1"
                            disabled={
                                processing ||
                                (step === 'categories' &&
                                    !hasCategories &&
                                    selected.length === 0)
                            }
                            onClick={next}
                        >
                            {processing && <Spinner />}
                            {
                                {
                                    welcome: t('Let’s start'),
                                    categories: hasCategories
                                        ? t('Continue')
                                        : t('Create these categories'),
                                    accounts: t('Continue'),
                                    done: t('Go to my budget'),
                                }[step]
                            }
                            {step !== 'done' && !processing && <ArrowRight />}
                        </Button>
                    </div>
                </footer>
            </div>
        </>
    );
}
