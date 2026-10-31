import { Plus } from 'lucide-react';
import { useId } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { t, tc } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type DraftCategory = { key: string; name: string; checked: boolean };

export type DraftGroup = {
    key: string;
    name: string;
    categories: DraftCategory[];
};

export type ExistingGroup = { id: number; name: string; categories: string[] };

let nextKey = 0;

export function newKey(): string {
    nextKey += 1;

    return `draft-${nextKey}`;
}

/**
 * The starter template as an editable draft, everything checked.
 */
export function draftFromTemplate(
    groups: { name: string; categories: string[] }[],
): DraftGroup[] {
    return groups.map((group) => ({
        key: newKey(),
        name: group.name,
        categories: group.categories.map((name) => ({
            key: newKey(),
            name,
            checked: true,
        })),
    }));
}

/**
 * What gets created: the checked categories with a name, in groups that keep
 * at least one.
 */
export function selectedGroups(draft: DraftGroup[]) {
    return draft
        .map((group) => ({
            name: group.name.trim(),
            categories: group.categories
                .filter((category) => category.checked)
                .map((category) => category.name.trim())
                .filter((name) => name !== ''),
        }))
        .filter((group) => group.name !== '' && group.categories.length > 0);
}

const inlineInput =
    'min-w-0 flex-1 rounded-md border border-transparent bg-transparent px-2 py-1 outline-none transition-colors hover:border-input focus:border-ring focus:ring-[3px] focus:ring-ring/30';

function GroupCard({
    group,
    onChange,
}: {
    group: DraftGroup;
    onChange: (group: DraftGroup) => void;
}) {
    const id = useId();
    const checkedCount = group.categories.filter((c) => c.checked).length;
    const allChecked = checkedCount === group.categories.length;

    const updateCategory = (key: string, change: Partial<DraftCategory>) =>
        onChange({
            ...group,
            categories: group.categories.map((category) =>
                category.key === key ? { ...category, ...change } : category,
            ),
        });

    return (
        <section className="rounded-2xl border border-sidebar-border/70 bg-card p-3 sm:p-4 dark:border-sidebar-border">
            <div className="flex items-center gap-2 border-b pb-2">
                <Checkbox
                    aria-label={t('Select the whole group')}
                    checked={
                        allChecked
                            ? true
                            : checkedCount > 0
                              ? 'indeterminate'
                              : false
                    }
                    onCheckedChange={(checked) =>
                        onChange({
                            ...group,
                            categories: group.categories.map((category) => ({
                                ...category,
                                checked: checked === true,
                            })),
                        })
                    }
                />
                <input
                    aria-label={t('Group name')}
                    value={group.name}
                    onChange={(event) =>
                        onChange({ ...group, name: event.target.value })
                    }
                    className={cn(inlineInput, 'font-medium')}
                />
                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                    {checkedCount}/{group.categories.length}
                </span>
            </div>

            <ul className="mt-1.5 space-y-0.5">
                {group.categories.map((category) => (
                    <li
                        key={category.key}
                        className="fx-swap flex items-center gap-2 rounded-lg pl-0.5 text-sm"
                    >
                        <Checkbox
                            id={`${id}-${category.key}`}
                            aria-label={category.name}
                            checked={category.checked}
                            onCheckedChange={(checked) =>
                                updateCategory(category.key, {
                                    checked: checked === true,
                                })
                            }
                        />
                        <input
                            aria-label={t('Category name')}
                            value={category.name}
                            placeholder={t('New category')}
                            autoFocus={category.name === ''}
                            onChange={(event) =>
                                updateCategory(category.key, {
                                    name: event.target.value,
                                })
                            }
                            className={cn(
                                inlineInput,
                                !category.checked &&
                                    'text-muted-foreground line-through decoration-muted-foreground/50',
                            )}
                        />
                    </li>
                ))}
            </ul>

            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="mt-1 text-muted-foreground"
                onClick={() =>
                    onChange({
                        ...group,
                        categories: [
                            ...group.categories,
                            { key: newKey(), name: '', checked: true },
                        ],
                    })
                }
            >
                <Plus />
                {t('Add a category')}
            </Button>
        </section>
    );
}

/**
 * Pick the starter categories: uncheck, rename inline, add your own. A
 * household that already has categories just sees them.
 */
export function CategoriesStep({
    draft,
    onChange,
    existingGroups,
    error,
}: {
    draft: DraftGroup[];
    onChange: (draft: DraftGroup[]) => void;
    existingGroups: ExistingGroup[];
    error?: string;
}) {
    const count = selectedGroups(draft).reduce(
        (sum, group) => sum + group.categories.length,
        0,
    );

    if (existingGroups.length > 0) {
        return (
            <div className="space-y-6">
                <header className="space-y-2 text-center">
                    <h1 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                        {t('Your envelopes are ready')}
                    </h1>
                    <p className="mx-auto max-w-md text-pretty text-muted-foreground">
                        {t(
                            'You can rename, add or reorder them at any time from the budget.',
                        )}
                    </p>
                </header>
                <div className="grid gap-3 sm:grid-cols-2">
                    {existingGroups.map((group) => (
                        <section
                            key={group.id}
                            className="rounded-2xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
                        >
                            <p className="mb-2 font-medium">{group.name}</p>
                            <div className="flex flex-wrap gap-1.5">
                                {group.categories.map((name) => (
                                    <span
                                        key={name}
                                        className="rounded-full bg-muted px-2.5 py-1 text-xs"
                                    >
                                        {name}
                                    </span>
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <header className="space-y-2 text-center">
                <h1 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                    {t('Choose your envelopes')}
                </h1>
                <p className="mx-auto max-w-md text-pretty text-muted-foreground">
                    {t(
                        'A starter set to begin with. Uncheck what you do not need, click a name to change it, add your own.',
                    )}
                </p>
            </header>

            <div className="grid items-start gap-3 sm:grid-cols-2">
                {draft.map((group) => (
                    <GroupCard
                        key={group.key}
                        group={group}
                        onChange={(changed) =>
                            onChange(
                                draft.map((item) =>
                                    item.key === changed.key ? changed : item,
                                ),
                            )
                        }
                    />
                ))}
            </div>

            <p
                className="text-center text-sm text-muted-foreground"
                aria-live="polite"
            >
                {tc(
                    '{0} No category selected|{1} One category selected|[2,*] :count categories selected',
                    count,
                )}
            </p>
            <InputError message={error} className="text-center" />
        </div>
    );
}
