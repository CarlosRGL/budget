import { Link } from '@inertiajs/react';
import {
    ChevronRight,
    Eye,
    EyeOff,
    GripVertical,
    MoreHorizontal,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { DragEvent } from 'react';
import { Money } from '@/components/money';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import { index as accountsIndex } from '@/routes/accounts';
import type { BudgetCategory, BudgetGroup } from '@/types/budget';
import { AssignedInput } from './assigned-input';
import { AvailablePill } from './available-pill';
import type { BudgetActions } from './budget-actions';
import { DeleteCategoryDialog, NameDialog } from './budget-dialogs';

type Props = {
    team: string;
    /** Groups to show, already filtered. */
    groups: BudgetGroup[];
    /** Every group and category, in order, for moving money and reordering. */
    allGroups: BudgetGroup[];
    readyToAssign: number;
    uncategorizedActivity: number;
    selectedId: number | null;
    onSelect: (categoryId: number) => void;
    /** Categories ticked for a bulk action. */
    checked: number[];
    onCheckedChange: (categoryIds: number[]) => void;
    /** Reordering is off while a filter or a search hides rows. */
    canReorder: boolean;
    actions: BudgetActions;
};

type Dragged = { kind: 'group' | 'category'; id: number } | null;

type Dialog =
    | { kind: 'rename-group'; group: BudgetGroup }
    | { kind: 'new-category'; group: BudgetGroup }
    | { kind: 'rename-category'; category: BudgetCategory }
    | null;

const columns =
    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 sm:grid-cols-[1.75rem_minmax(0,1fr)_8.5rem_8.5rem_8.5rem] sm:gap-x-2';

/** Group rows keep their fold button on a phone, in place of the checkbox. */
const groupColumns =
    'grid grid-cols-[1.5rem_minmax(0,1fr)_auto] items-center gap-x-2 sm:grid-cols-[1.75rem_minmax(0,1fr)_8.5rem_8.5rem_8.5rem]';

/** Bulk selection is a desktop tool: on a phone, a tap opens the category. */
const selectCell = 'hidden justify-center sm:flex';

/** Assigned and activity stay on wider screens: on a phone, the detail panel has them. */
const wide = 'hidden sm:block';

const COLLAPSED_KEY = 'budget.collapsedGroups';

function readCollapsed(): number[] {
    try {
        return JSON.parse(localStorage.getItem(COLLAPSED_KEY) ?? '[]');
    } catch {
        return [];
    }
}

/**
 * The structure sent to `budget.order` after a drop.
 */
function structure(groups: BudgetGroup[]) {
    return groups.map((group) => ({
        group_id: group.id,
        category_ids: group.categories.map((category) => category.id),
    }));
}

export function BudgetTable({
    team,
    groups,
    allGroups,
    readyToAssign,
    uncategorizedActivity,
    selectedId,
    onSelect,
    checked,
    onCheckedChange,
    canReorder,
    actions,
}: Props) {
    const visibleIds = groups.flatMap((group) =>
        group.categories.map((category) => category.id),
    );
    const checkState = (ids: number[]): boolean | 'indeterminate' => {
        const count = ids.filter((id) => checked.includes(id)).length;

        return count === 0
            ? false
            : count === ids.length
              ? true
              : 'indeterminate';
    };
    const setChecked = (ids: number[], value: boolean) =>
        onCheckedChange(
            value
                ? [...new Set([...checked, ...ids])]
                : checked.filter((id) => !ids.includes(id)),
        );
    const [collapsed, setCollapsed] = useState<number[]>(readCollapsed);
    const [dragged, setDragged] = useState<Dragged>(null);
    const [dropTarget, setDropTarget] = useState<string | null>(null);
    const [dialog, setDialog] = useState<Dialog>(null);
    const [deleting, setDeleting] = useState<BudgetCategory | null>(null);

    const toggle = (groupId: number) => {
        const next = collapsed.includes(groupId)
            ? collapsed.filter((id) => id !== groupId)
            : [...collapsed, groupId];
        setCollapsed(next);
        localStorage.setItem(COLLAPSED_KEY, JSON.stringify(next));
    };

    const dragProps = (kind: 'group' | 'category', id: number) =>
        canReorder
            ? {
                  draggable: true,
                  onDragStart: (event: DragEvent) => {
                      event.stopPropagation();
                      event.dataTransfer.effectAllowed = 'move';
                      setDragged({ kind, id });
                  },
                  onDragEnd: () => {
                      setDragged(null);
                      setDropTarget(null);
                  },
              }
            : {};

    /** Drop a category before another one, or at the end of a group. */
    const dropCategory = (groupId: number, beforeCategoryId: number | null) => {
        if (dragged?.kind !== 'category' || dragged.id === beforeCategoryId) {
            return;
        }

        const moving = allGroups
            .flatMap((group) => group.categories)
            .find((category) => category.id === dragged.id);

        if (!moving) {
            return;
        }

        const next = allGroups.map((group) => ({
            ...group,
            categories: group.categories.filter(
                (category) => category.id !== moving.id,
            ),
        }));
        const target = next.find((group) => group.id === groupId);

        if (!target) {
            return;
        }

        const index =
            beforeCategoryId === null
                ? target.categories.length
                : target.categories.findIndex(
                      (category) => category.id === beforeCategoryId,
                  );
        target.categories.splice(
            index < 0 ? target.categories.length : index,
            0,
            moving,
        );
        actions.reorder(structure(next));
    };

    /** Drop a group before another one. */
    const dropGroup = (beforeGroupId: number) => {
        if (dragged?.kind !== 'group' || dragged.id === beforeGroupId) {
            return;
        }

        const moving = allGroups.find((group) => group.id === dragged.id);

        if (!moving) {
            return;
        }

        const next = allGroups.filter((group) => group.id !== moving.id);
        next.splice(
            next.findIndex((group) => group.id === beforeGroupId),
            0,
            moving,
        );
        actions.reorder(structure(next));
    };

    const dropZone = (key: string, onDrop: () => void) =>
        canReorder && dragged
            ? {
                  onDragOver: (event: DragEvent) => {
                      event.preventDefault();
                      event.dataTransfer.dropEffect = 'move';
                      setDropTarget(key);
                  },
                  onDragLeave: () =>
                      setDropTarget((current) =>
                          current === key ? null : current,
                      ),
                  onDrop: (event: DragEvent) => {
                      event.preventDefault();
                      event.stopPropagation();
                      onDrop();
                      setDragged(null);
                      setDropTarget(null);
                  },
              }
            : {};

    return (
        <div className="-mx-3 overflow-hidden border-y border-sidebar-border/70 text-sm sm:mx-0 sm:rounded-xl sm:border dark:border-sidebar-border">
            <div
                className={cn(
                    columns,
                    'border-b bg-muted/50 px-3 py-2 text-xs font-medium text-muted-foreground uppercase',
                )}
            >
                <span className={selectCell}>
                    {visibleIds.length > 0 && (
                        <Checkbox
                            aria-label={t('Select all')}
                            checked={checkState(visibleIds)}
                            onCheckedChange={(value) =>
                                setChecked(visibleIds, value === true)
                            }
                        />
                    )}
                </span>
                <span>{t('Category')}</span>
                <span className={cn(wide, 'pr-2 text-right')}>
                    {t('Assigned')}
                </span>
                <span className={cn(wide, 'text-right')}>{t('Activity')}</span>
                <span className="text-right">{t('Available')}</span>
            </div>

            {uncategorizedActivity !== 0 && (
                <Link
                    href={`${accountsIndex(team).url}?filter=inbox`}
                    className={cn(
                        columns,
                        'border-b bg-amber-400/10 px-3 py-2 hover:bg-amber-400/15',
                    )}
                >
                    <span className="hidden sm:block" />
                    <span className="font-medium text-amber-600 dark:text-amber-300">
                        {t('Uncategorized transactions')}
                    </span>
                    <span className={wide} />
                    <Money
                        cents={uncategorizedActivity}
                        plain
                        className={cn(wide, 'text-right')}
                    />
                    <span className="text-right text-xs text-amber-600 underline-offset-2 hover:underline dark:text-amber-300">
                        {t('To categorize')}
                    </span>
                </Link>
            )}

            {groups.length === 0 && (
                <p className="px-4 py-10 text-center text-muted-foreground">
                    {t('No category matches.')}
                </p>
            )}

            {groups.map((group) => {
                const isCollapsed = collapsed.includes(group.id);

                return (
                    <div
                        key={group.id}
                        className={cn(
                            'border-b last:border-b-0',
                            group.hidden && 'opacity-60',
                        )}
                    >
                        <div
                            {...dragProps('group', group.id)}
                            {...dropZone(`group-${group.id}`, () =>
                                dragged?.kind === 'group'
                                    ? dropGroup(group.id)
                                    : dropCategory(group.id, null),
                            )}
                            className={cn(
                                groupColumns,
                                'group/row bg-muted/30 px-3 py-1.5 font-medium',
                                dropTarget === `group-${group.id}` &&
                                    'shadow-[inset_0_2px_0_var(--color-primary)]',
                            )}
                        >
                            <button
                                type="button"
                                onClick={() => toggle(group.id)}
                                aria-label={
                                    isCollapsed
                                        ? t('Expand :group', {
                                              group: group.name,
                                          })
                                        : t('Collapse :group', {
                                              group: group.name,
                                          })
                                }
                                aria-expanded={!isCollapsed}
                                className="flex size-6 items-center justify-center rounded text-muted-foreground hover:bg-muted"
                            >
                                <ChevronRight
                                    className={cn(
                                        'size-4 transition-transform',
                                        !isCollapsed && 'rotate-90',
                                    )}
                                />
                            </button>
                            <div className="flex min-w-0 items-center gap-1.5">
                                {group.categories.length > 0 && (
                                    <Checkbox
                                        className="hidden sm:flex"
                                        aria-label={t('Select :name', {
                                            name: group.name,
                                        })}
                                        checked={checkState(
                                            group.categories.map(
                                                (category) => category.id,
                                            ),
                                        )}
                                        onCheckedChange={(value) =>
                                            setChecked(
                                                group.categories.map(
                                                    (category) => category.id,
                                                ),
                                                value === true,
                                            )
                                        }
                                    />
                                )}
                                {canReorder && (
                                    <GripVertical className="hidden size-3.5 shrink-0 cursor-grab text-muted-foreground opacity-0 group-hover/row:opacity-100 sm:block" />
                                )}
                                <span className="truncate">{group.name}</span>
                                {group.hidden && (
                                    <Badge variant="outline">
                                        {t('Hidden group')}
                                    </Badge>
                                )}
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-6 opacity-0 group-hover/row:opacity-100 focus-visible:opacity-100 data-[state=open]:opacity-100"
                                            aria-label={t(
                                                'Actions for group :group',
                                                {
                                                    group: group.name,
                                                },
                                            )}
                                        >
                                            <MoreHorizontal />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="start">
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                setDialog({
                                                    kind: 'new-category',
                                                    group,
                                                })
                                            }
                                        >
                                            <Plus />
                                            {t('Add a category')}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                setDialog({
                                                    kind: 'rename-group',
                                                    group,
                                                })
                                            }
                                        >
                                            <Pencil />
                                            {t('Rename')}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                actions.updateGroup(group.id, {
                                                    hidden: !group.hidden,
                                                })
                                            }
                                        >
                                            {group.hidden ? (
                                                <Eye />
                                            ) : (
                                                <EyeOff />
                                            )}
                                            {group.hidden
                                                ? t('Show')
                                                : t('Hide')}
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            variant="destructive"
                                            disabled={
                                                group.categories.length > 0
                                            }
                                            onSelect={() =>
                                                actions.deleteGroup(group.id)
                                            }
                                        >
                                            <Trash2 />
                                            {group.categories.length > 0
                                                ? t('Delete (empty it first)')
                                                : t('Delete')}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                            <Money
                                cents={group.assigned}
                                className={cn(
                                    wide,
                                    'pr-2 text-right text-muted-foreground',
                                )}
                            />
                            <Money
                                cents={group.activity}
                                plain
                                className={cn(
                                    wide,
                                    'text-right text-muted-foreground',
                                )}
                            />
                            <Money
                                cents={group.available}
                                className="text-right text-muted-foreground"
                            />
                        </div>

                        {!isCollapsed &&
                            group.categories.map((category) => (
                                <div
                                    key={category.id}
                                    {...dragProps('category', category.id)}
                                    {...dropZone(
                                        `category-${category.id}`,
                                        () =>
                                            dropCategory(group.id, category.id),
                                    )}
                                    onClick={() => onSelect(category.id)}
                                    className={cn(
                                        columns,
                                        'group/row cursor-pointer border-t border-sidebar-border/40 px-3 py-2.5 hover:bg-muted/40 sm:py-1',
                                        selectedId === category.id &&
                                            'bg-primary/10 hover:bg-primary/10',
                                        checked.includes(category.id) &&
                                            selectedId !== category.id &&
                                            'bg-muted/50',
                                        category.hidden && 'opacity-60',
                                        dropTarget ===
                                            `category-${category.id}` &&
                                            'shadow-[inset_0_2px_0_var(--color-primary)]',
                                    )}
                                >
                                    <span
                                        className={selectCell}
                                        onClick={(event) =>
                                            event.stopPropagation()
                                        }
                                    >
                                        <Checkbox
                                            aria-label={t('Select :name', {
                                                name: category.name,
                                            })}
                                            checked={checked.includes(
                                                category.id,
                                            )}
                                            onCheckedChange={(value) =>
                                                setChecked(
                                                    [category.id],
                                                    value === true,
                                                )
                                            }
                                        />
                                    </span>
                                    <div className="flex min-w-0 items-center gap-1.5">
                                        {canReorder && (
                                            <GripVertical className="-ml-1 hidden size-3.5 shrink-0 cursor-grab text-muted-foreground opacity-0 group-hover/row:opacity-100 sm:block" />
                                        )}
                                        <div className="flex min-w-0 flex-col sm:flex-row sm:items-center sm:gap-1.5">
                                            <span className="flex min-w-0 items-center gap-1.5 sm:contents">
                                                <span className="truncate">
                                                    {category.name}
                                                </span>
                                                {category.hidden && (
                                                    <Badge variant="outline">
                                                        {t('Hidden')}
                                                    </Badge>
                                                )}
                                            </span>
                                            {category.target?.snoozed && (
                                                <span className="text-xs text-muted-foreground sm:hidden">
                                                    {t('Target snoozed')}
                                                </span>
                                            )}
                                            {category.target?.snoozed && (
                                                <Badge
                                                    variant="outline"
                                                    className="hidden sm:inline-flex"
                                                >
                                                    {t('Target snoozed')}
                                                </Badge>
                                            )}
                                            {category.target?.underfunded && (
                                                <span className="truncate text-xs text-amber-600 tabular-nums sm:shrink-0 dark:text-amber-300">
                                                    {t(':amount to go', {
                                                        amount: formatMoney(
                                                            category.target
                                                                .toGo,
                                                        ),
                                                    })}
                                                </span>
                                            )}
                                        </div>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={(event) =>
                                                        event.stopPropagation()
                                                    }
                                                    className="ml-auto hidden size-6 shrink-0 opacity-0 group-hover/row:opacity-100 focus-visible:opacity-100 data-[state=open]:opacity-100 sm:inline-flex"
                                                    aria-label={t(
                                                        'Actions for :category',
                                                        {
                                                            category:
                                                                category.name,
                                                        },
                                                    )}
                                                >
                                                    <MoreHorizontal />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent
                                                align="end"
                                                onClick={(event) =>
                                                    event.stopPropagation()
                                                }
                                            >
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setDialog({
                                                            kind: 'rename-category',
                                                            category,
                                                        })
                                                    }
                                                >
                                                    <Pencil />
                                                    {t('Rename')}
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        actions.updateCategory(
                                                            category.id,
                                                            {
                                                                hidden: !category.hidden,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {category.hidden ? (
                                                        <Eye />
                                                    ) : (
                                                        <EyeOff />
                                                    )}
                                                    {category.hidden
                                                        ? t('Show')
                                                        : t('Hide')}
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onSelect={() =>
                                                        setDeleting(category)
                                                    }
                                                >
                                                    <Trash2 />
                                                    {t('Delete…')}
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                    <span className={wide}>
                                        <AssignedInput
                                            cents={category.assigned}
                                            label={t('Assigned to :category', {
                                                category: category.name,
                                            })}
                                            onChange={(cents) =>
                                                actions.assign(
                                                    category.id,
                                                    cents,
                                                )
                                            }
                                        />
                                    </span>
                                    <Money
                                        cents={category.activity}
                                        plain
                                        className={cn(
                                            wide,
                                            'text-right',
                                            category.activity === 0 &&
                                                'text-muted-foreground',
                                        )}
                                    />
                                    <span className="flex justify-end">
                                        <AvailablePill
                                            category={category}
                                            groups={allGroups}
                                            readyToAssign={readyToAssign}
                                            actions={actions}
                                        />
                                    </span>
                                </div>
                            ))}
                    </div>
                );
            })}

            <NameDialog
                key={
                    dialog === null
                        ? 'closed'
                        : dialog.kind === 'rename-category'
                          ? `${dialog.kind}-${dialog.category.id}`
                          : `${dialog.kind}-${dialog.group.id}`
                }
                open={
                    dialog?.kind === 'rename-group' ||
                    dialog?.kind === 'new-category' ||
                    dialog?.kind === 'rename-category'
                }
                onOpenChange={(open) => !open && setDialog(null)}
                title={
                    dialog?.kind === 'new-category'
                        ? t('New category in :group', {
                              group: dialog.group.name,
                          })
                        : t('Rename')
                }
                description={
                    dialog?.kind === 'new-category'
                        ? t(
                              'An emoji at the start of the name is welcome (🛒 Groceries).',
                          )
                        : undefined
                }
                label={t('Name')}
                initialName={
                    dialog?.kind === 'rename-group'
                        ? dialog.group.name
                        : dialog?.kind === 'rename-category'
                          ? dialog.category.name
                          : ''
                }
                submitLabel={
                    dialog?.kind === 'new-category' ? t('Add') : t('Rename')
                }
                onSubmit={(name, close) => {
                    if (dialog?.kind === 'rename-group') {
                        actions.updateGroup(dialog.group.id, { name }, close);
                    }

                    if (dialog?.kind === 'new-category') {
                        actions.createCategory(dialog.group.id, name, close);
                    }

                    if (dialog?.kind === 'rename-category') {
                        actions.updateCategory(
                            dialog.category.id,
                            { name },
                            close,
                        );
                    }
                }}
            />

            <DeleteCategoryDialog
                category={deleting}
                groups={allGroups}
                onOpenChange={(open) => !open && setDeleting(null)}
                onConfirm={(replacementId, close) =>
                    deleting &&
                    actions.deleteCategory(deleting.id, replacementId, close)
                }
            />
        </div>
    );
}
