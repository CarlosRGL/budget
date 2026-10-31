import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatMonth } from '@/lib/dates';
import { t } from '@/lib/i18n';

/**
 * The month shown, with arrows to the previous and next ones.
 */
export function MonthNav({
    month,
    previousHref,
    nextHref,
}: {
    month: string;
    previousHref: string;
    nextHref: string;
}) {
    return (
        <div className="flex items-center gap-1">
            <Button variant="ghost" size="icon" asChild>
                <Link
                    href={previousHref}
                    aria-label={t('Previous month')}
                    preserveScroll
                >
                    <ChevronLeft />
                </Link>
            </Button>
            <h1 className="min-w-40 text-center text-xl font-semibold first-letter:uppercase sm:text-2xl">
                {formatMonth(month)}
            </h1>
            <Button variant="ghost" size="icon" asChild>
                <Link
                    href={nextHref}
                    aria-label={t('Next month')}
                    preserveScroll
                >
                    <ChevronRight />
                </Link>
            </Button>
        </div>
    );
}
