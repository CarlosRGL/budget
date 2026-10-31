import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    CheckCircle2,
    Circle,
    Copy,
    ExternalLink,
    XCircle,
} from 'lucide-react';
import type { ReactNode } from 'react';
import Heading from '@/components/heading';
import { Trans } from '@/components/trans';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index, setup } from '@/routes/bank-connections';

type Status = {
    appId: boolean;
    keyFound: boolean;
    keyValid: boolean;
    connected: boolean;
};

type Props = {
    status: Status;
    callbackUrl: string;
};

const KEY_PATH = 'storage/app/private/enablebanking.pem';

function Code({ children }: { children: ReactNode }) {
    return (
        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.85em] [overflow-wrap:anywhere]">
            {children}
        </code>
    );
}

function CopyButton({ text, label }: { text: string; label: string }) {
    const [copied, copy] = useClipboard();
    const done = copied === text;

    return (
        <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => copy(text)}
            aria-label={label}
        >
            {done ? <Check /> : <Copy />}
            {done ? t('Copied') : t('Copy')}
        </Button>
    );
}

function CodeBlock({ lines }: { lines: string[] }) {
    const text = lines.join('\n');

    return (
        <div className="flex items-start gap-2 rounded-lg border bg-muted/50 p-1.5 pl-3">
            <pre className="min-w-0 flex-1 overflow-x-auto py-1.5 font-mono text-xs leading-relaxed text-foreground">
                {text}
            </pre>
            <CopyButton text={text} label={t('Copy the commands')} />
        </div>
    );
}

function ExternalAnchor({
    href,
    children,
}: {
    href: string;
    children: ReactNode;
}) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1 font-medium underline underline-offset-2"
        >
            {children}
            <ExternalLink className="size-3" />
        </a>
    );
}

function StatusItem({
    done,
    label,
    hint,
    pending = false,
}: {
    done: boolean;
    label: string;
    hint?: ReactNode;
    pending?: boolean;
}) {
    const Icon = done ? CheckCircle2 : pending ? Circle : XCircle;

    return (
        <li className="flex gap-3 py-2.5">
            <Icon
                className={cn(
                    'mt-0.5 size-5 shrink-0',
                    done && 'text-emerald-600 dark:text-emerald-400',
                    !done && !pending && 'text-red-600 dark:text-red-400',
                    !done && pending && 'text-muted-foreground',
                )}
                aria-hidden
            />
            <div className="space-y-0.5">
                <p className="text-sm font-medium">
                    {label}
                    <span className="sr-only">
                        {' '}
                        {done ? t('(done)') : t('(to do)')}
                    </span>
                </p>
                {!done && hint && (
                    <p className="text-sm text-muted-foreground">{hint}</p>
                )}
            </div>
        </li>
    );
}

function Step({
    number,
    title,
    children,
}: {
    number: number;
    title: string;
    children: ReactNode;
}) {
    return (
        <li className="relative flex gap-4">
            <span
                className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground"
                aria-hidden
            >
                {number}
            </span>
            <div className="min-w-0 flex-1 space-y-3 pb-2">
                <h3 className="pt-1 font-semibold">{title}</h3>
                <div className="space-y-3 text-sm leading-relaxed text-muted-foreground [&_strong]:font-semibold [&_strong]:text-foreground">
                    {children}
                </div>
            </div>
        </li>
    );
}

export default function BankSyncSetup({ status, callbackUrl }: Props) {
    const { currentTeam } = usePage().props;
    const configured = status.appId && status.keyFound && status.keyValid;

    return (
        <>
            <Head title={t('Set up bank sync')} />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 md:p-6">
                <Heading
                    title={t('Set up bank sync')}
                    description={t(
                        'Bank sync goes through EnableBanking, a licensed open banking provider. On your own instance, you register a free EnableBanking application once; then each member connects their own bank.',
                    )}
                />

                <section
                    aria-labelledby="setup-status"
                    className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                >
                    <h2 id="setup-status" className="font-semibold">
                        {t('Status of this instance')}
                    </h2>
                    <ul className="mt-2 divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        <StatusItem
                            done={status.appId}
                            label={t('Application ID set')}
                            hint={
                                <Trans
                                    text={t(':variable is empty (step 4).')}
                                    values={{
                                        variable: (
                                            <Code>ENABLEBANKING_APP_ID</Code>
                                        ),
                                    }}
                                />
                            }
                        />
                        <StatusItem
                            done={status.keyFound}
                            label={t('Private key found')}
                            hint={
                                <Trans
                                    text={t(
                                        'No key file at :path and no key in the environment (step 4).',
                                    )}
                                    values={{ path: <Code>{KEY_PATH}</Code> }}
                                />
                            }
                        />
                        <StatusItem
                            done={status.keyValid}
                            pending={!status.keyFound}
                            label={t('Private key valid')}
                            hint={
                                status.keyFound
                                    ? t(
                                          'The key cannot be read as an RSA private key. Check that it is the private key (not the public one) and, in the environment, that it is base64-encoded.',
                                      )
                                    : undefined
                            }
                        />
                        <StatusItem
                            done={status.connected}
                            pending
                            label={t('A bank is connected')}
                            hint={t(
                                'Once the steps above are done, connect your bank (step 6).',
                            )}
                        />
                    </ul>
                    {configured && currentTeam && (
                        <div className="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-emerald-600/10 px-3 py-2.5 text-sm">
                            <p className="text-emerald-800 dark:text-emerald-300">
                                {t(
                                    'This instance is set up: members can connect their bank.',
                                )}
                            </p>
                            <Button size="sm" asChild>
                                <Link
                                    href={index({
                                        current_team: currentTeam.slug,
                                    })}
                                >
                                    {t('Bank connections')}
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </div>
                    )}
                </section>

                <section aria-labelledby="setup-steps" className="space-y-6">
                    <div className="space-y-1">
                        <h2 id="setup-steps" className="text-lg font-semibold">
                            {t('Step by step')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'Steps 1 to 5 are done once, by whoever runs this server. Allow about 15 minutes.',
                            )}
                        </p>
                    </div>

                    <ol className="space-y-6">
                        <Step
                            number={1}
                            title={t('Create an EnableBanking account')}
                        >
                            <p>
                                <Trans
                                    text={t(
                                        'Sign in on :link with your email address: the first sign-in creates your account. Then open the Control Panel.',
                                    )}
                                    values={{
                                        link: (
                                            <ExternalAnchor href="https://enablebanking.com/sign-in/">
                                                enablebanking.com
                                            </ExternalAnchor>
                                        ),
                                    }}
                                />
                            </p>
                        </Step>

                        <Step number={2} title={t('Register an application')}>
                            <p>
                                <Trans
                                    text={t(
                                        'In the Control Panel, open :menu and fill in the form:',
                                    )}
                                    values={{
                                        menu: (
                                            <strong>
                                                {t('API applications')}
                                            </strong>
                                        ),
                                    }}
                                />
                            </p>
                            <ul className="list-disc space-y-2 pl-5">
                                <li>
                                    <Trans
                                        text={t(
                                            'Environment: :value. Sandbox only shows test banks.',
                                        )}
                                        values={{
                                            value: <strong>Production</strong>,
                                        }}
                                    />
                                </li>
                                <li>
                                    {t(
                                        'Application name: any name, for example “Budget”.',
                                    )}
                                </li>
                                <li className="space-y-2">
                                    <p>
                                        {t(
                                            'Redirect URLs: the address of this instance below, exactly as written. It is where your bank sends you back after you allow access.',
                                        )}
                                    </p>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <code className="min-w-0 flex-1 rounded-lg border bg-muted/50 px-3 py-2 font-mono text-xs break-all text-foreground">
                                            {callbackUrl}
                                        </code>
                                        <CopyButton
                                            text={callbackUrl}
                                            label={t('Copy the redirect URL')}
                                        />
                                    </div>
                                    <p className="text-xs">
                                        {t(
                                            'If this address is wrong (for example http instead of https), fix APP_URL in the .env file first.',
                                        )}
                                    </p>
                                </li>
                                <li>
                                    {t(
                                        'Description, data protection email, privacy policy URL and terms of service URL: required in production and shown to whoever authorizes access. For a private household instance, your own email address and simple pages are enough.',
                                    )}
                                </li>
                            </ul>
                            <p>{t('Then choose how the key is created:')}</p>
                            <ul className="list-disc space-y-2 pl-5">
                                <li>
                                    <Trans
                                        text={t(
                                            ':option (simplest): your browser downloads a .pem file named after the application ID. It is your private key: keep it safe, it never leaves your computer.',
                                        )}
                                        values={{
                                            option: (
                                                <strong>
                                                    {t(
                                                        'Generate in the browser',
                                                    )}
                                                </strong>
                                            ),
                                        }}
                                    />
                                </li>
                                <li className="space-y-2">
                                    <p>
                                        <Trans
                                            text={t(
                                                ':option: generate a key pair on your computer, then paste the content of the public key in the form.',
                                            )}
                                            values={{
                                                option: (
                                                    <strong>
                                                        {t(
                                                            'Provide your own public key',
                                                        )}
                                                    </strong>
                                                ),
                                            }}
                                        />
                                    </p>
                                    <CodeBlock
                                        lines={[
                                            'openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out enablebanking.pem',
                                            'openssl rsa -in enablebanking.pem -pubout -out enablebanking.pub.pem',
                                        ]}
                                    />
                                </li>
                            </ul>
                            <p>
                                <Trans
                                    text={t('Finish with :button.')}
                                    values={{
                                        button: <strong>Register</strong>,
                                    }}
                                />
                            </p>
                        </Step>

                        <Step number={3} title={t('Copy the application ID')}>
                            <p>
                                {t(
                                    'Each application has an ID that looks like aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee. It is shown on the application in the Control Panel, and it is also the name of the .pem file downloaded by the browser.',
                                )}
                            </p>
                        </Step>

                        <Step number={4} title={t('Configure this instance')}>
                            <p>
                                <Trans
                                    text={t(
                                        'On the server, add the application ID to the :file file:',
                                    )}
                                    values={{ file: <Code>.env</Code> }}
                                />
                            </p>
                            <CodeBlock
                                lines={[
                                    'ENABLEBANKING_APP_ID=aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                                ]}
                            />
                            <p>
                                {t(
                                    'Then give it the private key, in one of three ways:',
                                )}
                            </p>
                            <ul className="list-disc space-y-2 pl-5">
                                <li>
                                    <Trans
                                        text={t(
                                            'copy the .pem file to :path (rename it);',
                                        )}
                                        values={{
                                            path: <Code>{KEY_PATH}</Code>,
                                        }}
                                    />
                                </li>
                                <li>
                                    <Trans
                                        text={t(
                                            'or put it elsewhere and set :variable to its path;',
                                        )}
                                        values={{
                                            variable: (
                                                <Code>
                                                    ENABLEBANKING_PRIVATE_KEY_PATH
                                                </Code>
                                            ),
                                        }}
                                    />
                                </li>
                                <li className="space-y-2">
                                    <p>
                                        <Trans
                                            text={t(
                                                'or, with Docker or a hosting platform without files, set :variable to the base64-encoded key:',
                                            )}
                                            values={{
                                                variable: (
                                                    <Code>
                                                        ENABLEBANKING_PRIVATE_KEY
                                                    </Code>
                                                ),
                                            }}
                                        />
                                    </p>
                                    <CodeBlock
                                        lines={[
                                            "base64 < enablebanking.pem | tr -d '\\n'",
                                        ]}
                                    />
                                </li>
                            </ul>
                            <p>
                                <Trans
                                    text={t(
                                        'If the configuration is cached, run :command (or restart the container), then reload this page: the first three checks above should be green.',
                                    )}
                                    values={{
                                        command: (
                                            <Code>
                                                php artisan config:clear
                                            </Code>
                                        ),
                                    }}
                                />
                            </p>
                        </Step>

                        <Step
                            number={5}
                            title={t(
                                'Activate the application by linking accounts',
                            )}
                        >
                            <p>
                                <Trans
                                    text={t(
                                        'A production application starts as Inactive. On the application, click :button, choose your bank, sign in to it and select your accounts.',
                                    )}
                                    values={{
                                        button: (
                                            <strong>
                                                Activate by linking accounts
                                            </strong>
                                        ),
                                    }}
                                />
                            </p>
                            <p>
                                {t(
                                    'The application then becomes active in restricted mode: no contract is needed for personal use, but it can only read the accounts linked this way.',
                                )}
                            </p>
                            <p>
                                {t(
                                    'Other members of the household: their accounts must be linked too, from the same Control Panel. Link accounts again, choose their bank and let them sign in to their bank themselves. The simplest is to do it together.',
                                )}
                            </p>
                        </Step>

                        <Step number={6} title={t('Connect your bank')}>
                            <p>
                                {t(
                                    'In Bank connections, click “Connect a bank”, choose your bank and allow access. Yes, once more: linking an account in the Control Panel does not authorize this app.',
                                )}
                            </p>
                            <p>
                                {t(
                                    'Then match each bank account with an account of the budget, or create one. Each member connects their own bank from their own login, and only they can renew the access later.',
                                )}
                            </p>
                        </Step>
                    </ol>
                </section>

                <section
                    aria-labelledby="setup-sync"
                    className="space-y-2 rounded-xl bg-muted/50 p-4 text-sm leading-relaxed text-muted-foreground"
                >
                    <h2
                        id="setup-sync"
                        className="font-semibold text-foreground"
                    >
                        {t('Automatic sync')}
                    </h2>
                    <p>
                        {t(
                            'Accounts sync 4 times a day (6:00, 11:00, 16:00 and 21:00): European rules (PSD2) allow a bank to be read 4 times a day when you are not there. “Sync now” works at any time.',
                        )}
                    </p>
                    <p>
                        <Trans
                            text={t(
                                'This needs the Laravel scheduler and a queue worker running on the server (:scheduler and :worker). The Docker image runs both.',
                            )}
                            values={{
                                scheduler: (
                                    <Code>php artisan schedule:work</Code>
                                ),
                                worker: <Code>php artisan queue:work</Code>,
                            }}
                        />
                    </p>
                    <p>
                        <Trans
                            text={t(
                                'Banks ask for a new consent every few months: a banner shows when it is time to renew. More details in :file, in the source code.',
                            )}
                            values={{
                                file: <Code>docs/enablebanking.md</Code>,
                            }}
                        />
                    </p>
                </section>
            </div>
        </>
    );
}

BankSyncSetup.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: props.currentTeam
        ? [
              {
                  title: t('Bank connections'),
                  href: index({ current_team: props.currentTeam.slug }),
              },
              {
                  title: t('Set up bank sync'),
                  href: setup({ current_team: props.currentTeam.slug }),
              },
          ]
        : [],
});
