import { router } from '@inertiajs/react';
import type { VisitOptions } from '@inertiajs/core';
import { toast } from 'sonner';
import {
    autoAssign as autoAssignRoute,
    order as orderRoute,
} from '@/routes/budget';
import { update as assignmentRoute } from '@/routes/budget/assignment';
import {
    destroy as destroyCategoryRoute,
    store as storeCategoryRoute,
    update as updateCategoryRoute,
} from '@/routes/budget/categories';
import {
    destroy as destroyGroupRoute,
    store as storeGroupRoute,
    update as updateGroupRoute,
} from '@/routes/budget/groups';
import { store as moveRoute } from '@/routes/budget/moves';
import { update as noteRoute } from '@/routes/budget/note';
import {
    bulk as bulkSnoozeRoute,
    destroy as unsnoozeRoute,
    store as snoozeRoute,
} from '@/routes/budget/snooze';
import {
    destroy as destroyTargetRoute,
    update as targetRoute,
} from '@/routes/budget/target';
import type { BudgetTarget } from '@/types/budget';

/**
 * Every change made on the budget screen: they keep the page state (selected
 * category, collapsed groups, scroll) and report validation errors in a toast.
 */
const options = (extra: Partial<VisitOptions> = {}): Partial<VisitOptions> => ({
    preserveScroll: true,
    preserveState: true,
    onError: (errors) => {
        const first = Object.values(errors)[0];

        if (first) {
            toast.error(first);
        }
    },
    ...extra,
});

export type AutoAssignStrategy =
    | 'underfunded'
    | 'last_month'
    | 'average'
    | 'reset';

export type TargetData = {
    type: BudgetTarget['type'];
    amount: number;
    weekday: number | null;
    due_date: string | null;
    repeat: 'yearly' | null;
};

export function budgetActions(team: string, month: string) {
    return {
        assign(categoryId: number, amount: number) {
            router.put(
                assignmentRoute({
                    current_team: team,
                    month,
                    category: categoryId,
                }).url,
                { amount },
                options(),
            );
        },

        move(
            fromCategoryId: number | null,
            toCategoryId: number | null,
            amount: number,
            onSuccess?: () => void,
        ) {
            router.post(
                moveRoute({ current_team: team, month }).url,
                {
                    from_category_id: fromCategoryId,
                    to_category_id: toCategoryId,
                    amount,
                },
                options({ onSuccess }),
            );
        },

        /** The whole month, or only the given categories. */
        autoAssign(
            strategy: AutoAssignStrategy,
            categoryIds?: number[],
            onSuccess?: () => void,
        ) {
            router.post(
                autoAssignRoute({ current_team: team, month }).url,
                {
                    strategy,
                    ...(categoryIds ? { category_ids: categoryIds } : {}),
                },
                options({ onSuccess }),
            );
        },

        saveMonthNote(note: string) {
            router.put(
                noteRoute({ current_team: team, month }).url,
                { note: note.trim() === '' ? null : note },
                options(),
            );
        },

        createGroup(name: string, onSuccess?: () => void) {
            router.post(
                storeGroupRoute(team).url,
                { name },
                options({ onSuccess }),
            );
        },

        updateGroup(
            groupId: number,
            data: { name?: string; hidden?: boolean },
            onSuccess?: () => void,
        ) {
            router.patch(
                updateGroupRoute({ current_team: team, group: groupId }).url,
                data,
                options({ onSuccess }),
            );
        },

        deleteGroup(groupId: number) {
            router.delete(
                destroyGroupRoute({ current_team: team, group: groupId }).url,
                options(),
            );
        },

        createCategory(groupId: number, name: string, onSuccess?: () => void) {
            router.post(
                storeCategoryRoute({ current_team: team, group: groupId }).url,
                { name },
                options({ onSuccess }),
            );
        },

        updateCategory(
            categoryId: number,
            data: { name?: string; note?: string | null; hidden?: boolean },
            onSuccess?: () => void,
        ) {
            router.patch(
                updateCategoryRoute({
                    current_team: team,
                    category: categoryId,
                }).url,
                data,
                options({ onSuccess }),
            );
        },

        deleteCategory(
            categoryId: number,
            replacementCategoryId: number,
            onSuccess?: () => void,
        ) {
            router.delete(
                destroyCategoryRoute({
                    current_team: team,
                    category: categoryId,
                }).url,
                options({
                    data: { replacement_category_id: replacementCategoryId },
                    onSuccess,
                }),
            );
        },

        saveTarget(
            categoryId: number,
            data: TargetData,
            onSuccess?: () => void,
        ) {
            router.put(
                targetRoute({ current_team: team, category: categoryId }).url,
                data,
                options({ onSuccess }),
            );
        },

        deleteTarget(categoryId: number, onSuccess?: () => void) {
            router.delete(
                destroyTargetRoute({
                    current_team: team,
                    category: categoryId,
                }).url,
                options({ onSuccess }),
            );
        },

        snooze(categoryId: number, snoozed: boolean) {
            const route = snoozed ? snoozeRoute : unsnoozeRoute;
            const url = route({
                current_team: team,
                month,
                category: categoryId,
            }).url;

            if (snoozed) {
                router.post(url, {}, options());
            } else {
                router.delete(url, options());
            }
        },

        snoozeMany(
            categoryIds: number[],
            snoozed: boolean,
            onSuccess?: () => void,
        ) {
            router.put(
                bulkSnoozeRoute({ current_team: team, month }).url,
                { category_ids: categoryIds, snoozed },
                options({ onSuccess }),
            );
        },

        reorder(groups: { group_id: number; category_ids: number[] }[]) {
            router.put(orderRoute(team).url, { groups }, options());
        },
    };
}

export type BudgetActions = ReturnType<typeof budgetActions>;
