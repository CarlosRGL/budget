import { ArrowUp, CornerDownRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

/**
 * A small envelope as drawn in the illustrations: an emoji, a name and the
 * money inside.
 */
function MiniEnvelope({
    emoji,
    name,
    amount,
    tone = 'neutral',
    className,
}: {
    emoji: string;
    name: string;
    amount: number;
    tone?: 'neutral' | 'positive' | 'negative';
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex min-w-0 items-center gap-2 rounded-lg bg-background px-2.5 py-1.5 text-xs shadow-xs ring-1 ring-border',
                className,
            )}
        >
            <span aria-hidden>{emoji}</span>
            <span className="truncate">{name}</span>
            <span
                className={cn(
                    'ml-auto shrink-0 rounded-full px-1.5 py-0.5 font-medium whitespace-nowrap tabular-nums',
                    tone === 'positive' &&
                        'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
                    tone === 'negative' &&
                        'bg-red-500/15 text-red-700 dark:text-red-300',
                    tone === 'neutral' && 'bg-muted text-muted-foreground',
                )}
            >
                {formatMoney(amount)}
            </span>
        </div>
    );
}

function Idea({
    number,
    title,
    text,
    picture,
}: {
    number: number;
    title: string;
    text: string;
    picture: ReactNode;
}) {
    return (
        <li className="flex flex-col overflow-hidden rounded-2xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <div
                aria-hidden
                className="flex min-h-32 flex-col justify-center gap-1.5 bg-muted/50 px-5 py-4"
            >
                {picture}
            </div>
            <div className="space-y-1.5 p-5">
                <p className="flex items-center gap-2 font-medium">
                    <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary text-[0.7rem] font-semibold text-primary-foreground">
                        {number}
                    </span>
                    {title}
                </p>
                <p className="text-sm leading-relaxed text-muted-foreground">
                    {text}
                </p>
            </div>
        </li>
    );
}

/**
 * Envelope budgeting in four ideas, each with a small picture made of the
 * same pieces as the budget screen.
 */
export function WelcomeStep({ household }: { household: string }) {
    return (
        <div className="space-y-6">
            <header className="space-y-2 text-center">
                <h1 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                    {t('Welcome to :household', { household })}
                </h1>
                <p className="mx-auto max-w-md text-pretty text-muted-foreground">
                    {t(
                        'Envelope budgeting comes down to four simple ideas. Two minutes and you are ready.',
                    )}
                </p>
            </header>

            <ol className="grid gap-4 sm:grid-cols-2">
                <Idea
                    number={1}
                    title={t('Give every euro a job')}
                    text={t(
                        'When money comes in, decide what it is for before spending it: rent, groceries, the holidays. Only with the money you actually have.',
                    )}
                    picture={
                        <div className="grid gap-1.5">
                            <span className="w-fit rounded-lg bg-emerald-500/15 px-2.5 py-1 text-sm font-semibold whitespace-nowrap text-emerald-700 tabular-nums dark:text-emerald-300">
                                {formatMoney(1_200_00)}
                            </span>
                            {[
                                ['🏠', t('Rent or mortgage'), 850_00],
                                ['🛒', t('Groceries'), 350_00],
                            ].map(([emoji, name, amount]) => (
                                <div
                                    key={emoji}
                                    className="flex items-center gap-1.5 pl-2"
                                >
                                    <CornerDownRight className="size-3.5 shrink-0 text-muted-foreground" />
                                    <MiniEnvelope
                                        emoji={String(emoji)}
                                        name={String(name)}
                                        amount={Number(amount)}
                                        className="flex-1"
                                    />
                                </div>
                            ))}
                        </div>
                    }
                />
                <Idea
                    number={2}
                    title={t('Categories are envelopes')}
                    text={t(
                        'Each category holds the money set aside for it. When you spend, the money comes out of the matching envelope.',
                    )}
                    picture={
                        <>
                            <MiniEnvelope
                                emoji="🛒"
                                name={t('Groceries')}
                                amount={212_40}
                                tone="positive"
                            />
                            <MiniEnvelope
                                emoji="⛽"
                                name={t('Fuel')}
                                amount={64_00}
                                tone="positive"
                                className="ml-3"
                            />
                            <MiniEnvelope
                                emoji="🎁"
                                name={t('Gifts')}
                                amount={30_00}
                                tone="positive"
                                className="ml-6"
                            />
                        </>
                    }
                />
                <Idea
                    number={3}
                    title={t('“Ready to Assign” is money waiting for a job')}
                    text={t(
                        'It is the money in your accounts not yet put in an envelope. Aim for zero: every euro has somewhere to go.',
                    )}
                    picture={
                        <div className="mx-auto w-full max-w-56 rounded-xl bg-emerald-500/10 px-4 py-2.5 ring-1 ring-emerald-500/30 ring-inset">
                            <p className="text-xs font-medium text-muted-foreground">
                                {t('Ready to Assign')}
                            </p>
                            <p className="text-xl leading-tight font-semibold text-emerald-700 tabular-nums dark:text-emerald-300">
                                {formatMoney(245_00)}
                            </p>
                        </div>
                    }
                />
                <Idea
                    number={4}
                    title={t('Overspent? Move money')}
                    text={t(
                        'If an envelope goes red, no drama: take money from another envelope that can spare it. The budget follows real life.',
                    )}
                    picture={
                        <div className="grid gap-1">
                            <MiniEnvelope
                                emoji="🍽️"
                                name={t('Restaurants')}
                                amount={-25_00}
                                tone="negative"
                            />
                            <span className="flex items-center gap-1.5 self-center pl-6 text-xs text-muted-foreground tabular-nums">
                                <ArrowUp className="size-3.5" />
                                {formatMoney(25_00)}
                            </span>
                            <MiniEnvelope
                                emoji="🎬"
                                name={t('Going out')}
                                amount={40_00}
                                tone="positive"
                            />
                        </div>
                    }
                />
            </ol>
        </div>
    );
}
