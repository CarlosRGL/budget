import { Head, router, usePage } from '@inertiajs/react';
import { Eye, EyeOff, FolderPlus, Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { budgetActions } from '@/components/budget/budget-actions';
import { NameDialog } from '@/components/budget/budget-dialogs';
import { BudgetHeader } from '@/components/budget/budget-header';
import { BudgetTable } from '@/components/budget/budget-table';
import { BulkBar } from '@/components/budget/bulk-bar';
import { CategoryDetail } from '@/components/budget/category-detail';
import { MonthSummary } from '@/components/budget/month-summary';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import { Badge } from '@/components/ui/badge';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { useMediaQuery } from '@/hooks/use-media-query';
import { Button } from '@/components/ui/button';
import { locale, t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show } from '@/routes/budget';
import type { DashboardInvitation } from '@/types';
import type {
    BudgetCategory,
    BudgetCategoryDetail,
    BudgetPageProps,
} from '@/types/budget';

type Props = BudgetPageProps & {
    pendingInvitations?: DashboardInvitation[];
};

type Filter =
    | 'all'
    | 'overspent'
    | 'snoozed'
    | 'underfunded'
    | 'overfunded'
    | 'available';

const filters: {
    key: Filter;
    label: string;
    matches: (category: BudgetCategory) => boolean;
}[] = [
    { key: 'all', label: t('All'), matches: () => true },
    {
        key: 'overspent',
        label: t('Overspent'),
        matches: (category) => category.available < 0,
    },
    {
        key: 'snoozed',
        label: t('Target snoozed'),
        matches: (category) => category.target?.snoozed === true,
    },
    {
        key: 'underfunded',
        label: t('Underfunded'),
        matches: (category) => category.target?.underfunded === true,
    },
    {
        key: 'overfunded',
        label: t('Overfunded'),
        matches: (category) => category.target?.overfunded === true,
    },
    {
        key: 'available',
        label: t('Money available'),
        matches: (category) => category.available > 0,
    },
];

function selectedFromUrl(url: string): number | null {
    const value = new URL(url, 'http://localhost').searchParams.get('category');

    return value ? Number(value) : null;
}

/**
 * The budget of one month (design §5.5): assign money, move it between
 * envelopes, and look at one category in the detail panel.
 */
export default function BudgetShow({
    month,
    previousMonth,
    nextMonth,
    readyToAssign,
    uncategorizedActivity,
    overspentCount,
    underfundedCount,
    note,
    groups,
    moves,
    detail,
    pendingInvitations = [],
}: Props) {
    const page = usePage();
    const team = page.props.currentTeam?.slug ?? '';
    const actions = useMemo(() => budgetActions(team, month), [team, month]);

    // The detail sits next to the table on wide screens, in a sheet otherwise.
    const wide = useMediaQuery('(min-width: 1280px)');
    const [showInvitations, setShowInvitations] = useState(
        pendingInvitations.length > 0,
    );
    const [filter, setFilter] = useState<Filter>('all');
    const [search, setSearch] = useState('');
    const [showHidden, setShowHidden] = useState(false);
    const [newGroupOpen, setNewGroupOpen] = useState(false);
    const [checked, setChecked] = useState<number[]>([]);
    const clearChecked = useCallback(() => setChecked([]), []);
    const [selectedId, setSelectedId] = useState<number | null>(() =>
        selectedFromUrl(page.url),
    );
    const [details, setDetails] = useState<
        Record<string, BudgetCategoryDetail>
    >({});

    // `detail` only comes with partial reloads asking for it: keep each one
    // for the month, so assigning money does not blank the panel.
    useEffect(() => {
        if (detail) {
            setDetails((current) => ({
                ...current,
                [`${month}-${detail.categoryId}`]: detail,
            }));
        }
    }, [detail, month]);

    const selectedDetail =
        selectedId === null
            ? null
            : (details[`${month}-${selectedId}`] ?? null);

    useEffect(() => {
        if (selectedId !== null && selectedDetail === null) {
            router.reload({
                only: ['detail'],
                data: { category: selectedId },
            });
        }
    }, [selectedId, selectedDetail]);

    const select = (categoryId: number | null) => {
        setSelectedId(categoryId);
        const url = new URL(window.location.href);

        if (categoryId === null) {
            url.searchParams.delete('category');
        } else {
            url.searchParams.set('category', String(categoryId));
        }

        window.history.replaceState(window.history.state, '', url);
    };

    const hiddenCount = groups.reduce(
        (count, group) =>
            count +
            (group.hidden
                ? group.categories.length
                : group.categories.filter((category) => category.hidden)
                      .length),
        0,
    );

    const activeFilter = filters.find((candidate) => candidate.key === filter)!;
    const query = search.trim().toLocaleLowerCase(locale());
    const filtering = filter !== 'all' || query !== '';

    const visibleGroups = groups
        .filter((group) => showHidden || !group.hidden)
        .map((group) => ({
            ...group,
            categories: group.categories.filter(
                (category) =>
                    (showHidden || !category.hidden) &&
                    activeFilter.matches(category) &&
                    (query === '' ||
                        category.name
                            .toLocaleLowerCase(locale())
                            .includes(query) ||
                        group.name.toLocaleLowerCase(locale()).includes(query)),
            ),
        }))
        .filter((group) => !filtering || group.categories.length > 0);

    const selected = groups
        .flatMap((group) =>
            group.categories.map((category) => ({ category, group })),
        )
        .find(({ category }) => category.id === selectedId);

    const counts: Partial<Record<Filter, number>> = {
        overspent: overspentCount,
        underfunded: underfundedCount,
    };

    return (
        <>
            <Head title={t('Budget')} />
            <PendingInvitationsModal
                invitations={pendingInvitations}
                open={pendingInvitations.length > 0 && showInvitations}
                onOpenChange={setShowInvitations}
            />
            <div className="flex flex-1 flex-col gap-3 p-3 sm:gap-4 sm:p-4 md:p-6">
                <BudgetHeader
                    key={month}
                    team={team}
                    month={month}
                    previousMonth={previousMonth}
                    nextMonth={nextMonth}
                    readyToAssign={readyToAssign}
                    note={note}
                    moves={moves}
                    actions={actions}
                />

                <div className="flex flex-wrap items-center gap-2">
                    <div className="-mx-3 flex gap-1 overflow-x-auto px-3 sm:mx-0 sm:flex-wrap sm:px-0">
                        {filters.map((candidate) => (
                            <Button
                                key={candidate.key}
                                size="sm"
                                variant={
                                    filter === candidate.key
                                        ? 'secondary'
                                        : 'ghost'
                                }
                                className={cn(
                                    'shrink-0',
                                    filter !== candidate.key &&
                                        'text-muted-foreground',
                                )}
                                onClick={() => setFilter(candidate.key)}
                            >
                                {candidate.label}
                                {!!counts[candidate.key] && (
                                    <Badge
                                        className={cn(
                                            'h-5 px-1.5',
                                            candidate.key === 'overspent'
                                                ? 'bg-red-500 text-white'
                                                : 'bg-amber-400 text-amber-950',
                                        )}
                                    >
                                        {counts[candidate.key]}
                                    </Badge>
                                )}
                            </Button>
                        ))}
                    </div>
                    <div className="flex w-full items-center gap-2 sm:ml-auto sm:w-auto">
                        <label className="relative flex-1 sm:flex-none">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <input
                                type="search"
                                aria-label={t('Search for a category')}
                                placeholder={t('Search…')}
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                className="h-8 w-full rounded-md border bg-transparent pr-2 pl-8 text-sm outline-none focus:ring-2 focus:ring-ring/30 sm:w-48"
                            />
                        </label>
                        {hiddenCount > 0 && (
                            <Button
                                size="sm"
                                variant="ghost"
                                className="text-muted-foreground"
                                onClick={() => setShowHidden(!showHidden)}
                            >
                                {showHidden ? <EyeOff /> : <Eye />}
                                <span className="max-sm:sr-only">
                                    {showHidden
                                        ? t('Hide hidden categories')
                                        : t('Hidden (:count)', {
                                              count: hiddenCount,
                                          })}
                                </span>
                                {!showHidden && (
                                    <span className="sm:hidden">
                                        {hiddenCount}
                                    </span>
                                )}
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setNewGroupOpen(true)}
                        >
                            <FolderPlus />
                            <span className="max-sm:sr-only">{t('Group')}</span>
                        </Button>
                    </div>
                </div>

                <div
                    key={month}
                    className={cn(
                        'fx-swap grid items-start gap-4',
                        wide && 'grid-cols-[minmax(0,1fr)_22rem]',
                    )}
                >
                    {groups.length === 0 ? (
                        <div className="rounded-xl border border-dashed p-10 text-center">
                            <p className="font-medium">
                                {t('No categories yet.')}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {t(
                                    'Start with a group (“Everyday spending”, “Bills”…), then add your envelopes to it.',
                                )}
                            </p>
                            <Button
                                className="mt-4"
                                onClick={() => setNewGroupOpen(true)}
                            >
                                <FolderPlus />
                                {t('Create a group')}
                            </Button>
                        </div>
                    ) : (
                        <BudgetTable
                            team={team}
                            groups={visibleGroups}
                            allGroups={groups}
                            readyToAssign={readyToAssign}
                            uncategorizedActivity={uncategorizedActivity}
                            selectedId={selectedId}
                            onSelect={(id) =>
                                select(id === selectedId ? null : id)
                            }
                            checked={checked}
                            onCheckedChange={setChecked}
                            canReorder={!filtering}
                            actions={actions}
                        />
                    )}

                    {wide && (
                        <div className="sticky top-4">
                            {selected ? (
                                <CategoryDetail
                                    key={`${month}-${selected.category.id}`}
                                    category={selected.category}
                                    groupName={selected.group.name}
                                    detail={selectedDetail}
                                    actions={actions}
                                    onClose={() => select(null)}
                                />
                            ) : (
                                <MonthSummary
                                    month={month}
                                    groups={groups}
                                    readyToAssign={readyToAssign}
                                    uncategorizedActivity={
                                        uncategorizedActivity
                                    }
                                    actions={actions}
                                    onSelect={select}
                                />
                            )}
                        </div>
                    )}
                </div>
            </div>

            <Sheet
                open={!wide && selected !== undefined}
                onOpenChange={(open) => !open && select(null)}
            >
                <SheetContent
                    className="w-full overflow-y-auto p-4 sm:max-w-md"
                    // No keyboard popping up on phones as the sheet opens.
                    onOpenAutoFocus={(event) => event.preventDefault()}
                >
                    <SheetTitle className="sr-only">
                        {selected?.category.name ?? t('Category')}
                    </SheetTitle>
                    <SheetDescription className="sr-only">
                        {t('Category detail for this month')}
                    </SheetDescription>
                    {selected && (
                        <CategoryDetail
                            key={`${month}-${selected.category.id}`}
                            category={selected.category}
                            groupName={selected.group.name}
                            detail={selectedDetail}
                            actions={actions}
                            onClose={() => select(null)}
                            bare
                        />
                    )}
                </SheetContent>
            </Sheet>

            <BulkBar
                categories={groups.flatMap((group) =>
                    group.categories.filter((category) =>
                        checked.includes(category.id),
                    ),
                )}
                actions={actions}
                onClear={clearChecked}
            />

            <NameDialog
                key={newGroupOpen ? 'new-group-open' : 'new-group'}
                open={newGroupOpen}
                onOpenChange={setNewGroupOpen}
                title={t('New group')}
                label={t('Group name')}
                submitLabel={t('Create')}
                onSubmit={(name, close) => actions.createGroup(name, close)}
            />
        </>
    );
}

BudgetShow.layout = (props: {
    currentTeam?: { slug: string } | null;
    month: string;
}) => ({
    breadcrumbs: [
        {
            title: t('Budget'),
            href: props.currentTeam
                ? show({
                      current_team: props.currentTeam.slug,
                      month: props.month,
                  })
                : '/',
        },
    ],
});
