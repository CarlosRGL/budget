import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { index as bankConnections } from '@/routes/bank-connections';
import { intlLocale, t } from '@/lib/i18n';

const dateFormat = new Intl.DateTimeFormat(intlLocale(), { dateStyle: 'long' });

/**
 * Warns the household when a bank consent ends within 7 days, or ended.
 */
export function BankRenewalBanner() {
    const { bankRenewals, currentTeam } = usePage().props;

    if (
        !currentTeam ||
        currentTeam.role === 'viewer' ||
        !bankRenewals ||
        bankRenewals.length === 0
    ) {
        return null;
    }

    return (
        <div className="mx-4 mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-amber-400/60 bg-amber-400/10 px-3 py-2 text-sm">
            <AlertTriangle className="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <ul className="flex-1">
                {bankRenewals.map((renewal) => (
                    <li key={renewal.id}>
                        {renewal.expired
                            ? t(
                                  'Access to :bank (:owner) has expired: syncing has stopped.',
                                  {
                                      bank: renewal.aspspName,
                                      owner: renewal.owner,
                                  },
                              )
                            : renewal.validUntil
                              ? t(
                                    'Access to :bank (:owner) expires on :date.',
                                    {
                                        bank: renewal.aspspName,
                                        owner: renewal.owner,
                                        date: dateFormat.format(
                                            new Date(renewal.validUntil),
                                        ),
                                    },
                                )
                              : t('Access to :bank (:owner) expires soon.', {
                                    bank: renewal.aspspName,
                                    owner: renewal.owner,
                                })}
                    </li>
                ))}
            </ul>
            <Link
                href={bankConnections(currentTeam.slug)}
                className="font-medium underline underline-offset-4"
            >
                {t('Renew')}
            </Link>
        </div>
    );
}
