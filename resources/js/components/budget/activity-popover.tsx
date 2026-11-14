import type { ReactNode } from "react";
import { useEffect, useRef, useState } from "react";
import { Money } from "@/components/money";
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from "@/components/ui/popover";
import { Skeleton } from "@/components/ui/skeleton";
import { intlLocale, t, tc } from "@/lib/i18n";
import { activity as activityRoute } from "@/routes/budget";

type ActivityTransaction = {
    id: number;
    date: string;
    payee: string | null;
    account: string;
    memo: string | null;
    amount: number;
};

type Props = {
    team: string;
    month: string;
    categoryId: number;
    /** The activity shown: the list is fetched again when it changes. */
    activity: number;
    children: ReactNode;
};

const OPEN_DELAY = 250;
const CLOSE_DELAY = 150;

const dayFormat = new Intl.DateTimeFormat(intlLocale(), {
    day: "2-digit",
    month: "short",
});

/** Fetched lists, by URL and activity, kept for the page's lifetime. */
const cache = new Map<string, Promise<ActivityTransaction[]>>();

function load(url: string, activity: number): Promise<ActivityTransaction[]> {
    const key = `${url}#${activity}`;
    let request = cache.get(key);

    if (!request) {
        request = fetch(url, {
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json() as Promise<{
                    transactions: ActivityTransaction[];
                }>;
            })
            .then((data) => data.transactions)
            .catch((error: unknown) => {
                cache.delete(key);
                throw error;
            });
        cache.set(key, request);
    }

    return request;
}

/**
 * Hovering or clicking a category's activity lists the transactions behind it.
 */
export function ActivityPopover({
    team,
    month,
    categoryId,
    activity,
    children,
}: Props) {
    const [open, setOpen] = useState(false);
    const [transactions, setTransactions] = useState<
        ActivityTransaction[] | null
    >(null);
    const [failed, setFailed] = useState(false);
    const timer = useRef<number | undefined>(undefined);

    const url = activityRoute({
        current_team: team,
        month,
        category: categoryId,
    }).url;

    useEffect(() => {
        if (!open) {
            return;
        }

        let current = true;

        load(url, activity)
            .then((rows) => current && setTransactions(rows))
            .catch(() => current && setFailed(true));

        return () => {
            current = false;
        };
    }, [open, url, activity]);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    if (activity === 0) {
        return <>{children}</>;
    }

    const later = (next: boolean, delay: number) => {
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => {
            if (next) {
                setFailed(false);
            }

            setOpen(next);
        }, delay);
    };

    const hoverHandlers = {
        onPointerEnter: () => later(true, OPEN_DELAY),
        onPointerLeave: () => later(false, CLOSE_DELAY),
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverAnchor asChild>
                <span
                    tabIndex={0}
                    className="cursor-default rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                    onFocus={() => later(true, 0)}
                    onBlur={() => later(false, 0)}
                    onClick={(event) => {
                        event.stopPropagation();
                        later(true, 0);
                    }}
                    {...hoverHandlers}
                >
                    {children}
                </span>
            </PopoverAnchor>
            <PopoverContent
                align="end"
                className="w-80 p-0"
                onOpenAutoFocus={(event) => event.preventDefault()}
                onCloseAutoFocus={(event) => event.preventDefault()}
                {...hoverHandlers}
            >
                {failed ? (
                    <p className="p-3 text-sm text-muted-foreground">
                        {t("Could not load the transactions.")}
                    </p>
                ) : transactions === null ? (
                    <div className="space-y-2 p-3">
                        <Skeleton className="h-4 w-full" />
                        <Skeleton className="h-4 w-4/5" />
                        <Skeleton className="h-4 w-3/5" />
                    </div>
                ) : (
                    <>
                        <ul className="max-h-80 divide-y overflow-y-auto">
                            {transactions.map((transaction, index) => (
                                <li
                                    key={`${transaction.id}-${index}`}
                                    className="flex items-start gap-3 px-3 py-2 text-sm"
                                >
                                    <span className="w-12 shrink-0 text-xs text-muted-foreground tabular-nums">
                                        {dayFormat.format(
                                            new Date(
                                                `${transaction.date}T12:00:00`,
                                            ),
                                        )}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate">
                                            {transaction.payee ?? t("No payee")}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {transaction.memo
                                                ? `${transaction.account} · ${transaction.memo}`
                                                : transaction.account}
                                        </span>
                                    </span>
                                    <Money
                                        cents={transaction.amount}
                                        plain
                                        positive
                                        className="shrink-0"
                                    />
                                </li>
                            ))}
                        </ul>
                        <p className="border-t px-3 py-2 text-xs text-muted-foreground">
                            {tc(
                                ":count transaction|:count transactions",
                                transactions.length,
                            )}
                        </p>
                    </>
                )}
            </PopoverContent>
        </Popover>
    );
}
