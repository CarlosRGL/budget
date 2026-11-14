import { router } from "@inertiajs/react";
import { toast } from "sonner";
import { t } from "@/lib/i18n";
import { restore as restoreRoute } from "@/routes/budget";
import type { BudgetGroup } from "@/types/budget";

/**
 * Undo and redo of the budget screen (Cmd/Ctrl+Z, Shift+Cmd/Ctrl+Z).
 *
 * Every change made there (assigning, moving money, automatic assignment,
 * pausing targets) comes down to assigned amounts and paused targets of a
 * month. A step is the difference between the page before and after the
 * change; undoing it asks the server to put the previous values back, which it
 * refuses if one of them changed since. Steps live until the page is reloaded.
 */

/** The values a step can change, for one month. */
export type BudgetSnapshot = {
    month: string;
    assigned: Map<number, number>;
    snoozed: Map<number, boolean>;
};

type Change<T> = { categoryId: number; before: T; after: T };

type Step = {
    team: string;
    month: string;
    assignments: Change<number>[];
    snoozes: Change<boolean>[];
};

const LIMIT = 50;

const undoStack: Step[] = [];
const redoStack: Step[] = [];
let busy = false;

export function snapshotOf(
    month: string,
    groups: BudgetGroup[],
): BudgetSnapshot {
    const assigned = new Map<number, number>();
    const snoozed = new Map<number, boolean>();

    for (const group of groups) {
        for (const category of group.categories) {
            assigned.set(category.id, category.assigned);

            if (category.target) {
                snoozed.set(category.id, category.target.snoozed);
            }
        }
    }

    return { month, assigned, snoozed };
}

function changes<T>(
    before: Map<number, T>,
    after: Map<number, T>,
): Change<T>[] {
    const result: Change<T>[] = [];

    for (const [categoryId, value] of after) {
        const previous = before.get(categoryId);

        if (previous !== undefined && previous !== value) {
            result.push({ categoryId, before: previous, after: value });
        }
    }

    return result;
}

/**
 * Keep the change between two states of the same month as a step to undo.
 */
export function record(
    team: string,
    before: BudgetSnapshot,
    after: BudgetSnapshot,
): void {
    if (before.month !== after.month) {
        return;
    }

    const step: Step = {
        team,
        month: before.month,
        assignments: changes(before.assigned, after.assigned),
        snoozes: changes(before.snoozed, after.snoozed),
    };

    if (step.assignments.length === 0 && step.snoozes.length === 0) {
        return;
    }

    undoStack.push(step);
    undoStack.splice(0, Math.max(0, undoStack.length - LIMIT));
    redoStack.length = 0;
}

function send(step: Step, kind: "undo" | "redo", onDone: () => void): void {
    const backwards = kind === "undo";
    const from = <T>(change: Change<T>) =>
        backwards ? change.after : change.before;
    const to = <T>(change: Change<T>) =>
        backwards ? change.before : change.after;

    busy = true;

    router.put(
        restoreRoute({ current_team: step.team, month: step.month }).url,
        {
            kind,
            assignments: step.assignments.map((change) => ({
                category_id: change.categoryId,
                from: from(change),
                to: to(change),
            })),
            snoozes: step.snoozes.map((change) => ({
                category_id: change.categoryId,
                from: from(change),
                to: to(change),
            })),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onDone,
            onError: (errors) => {
                const first = Object.values(errors)[0];

                if (first) {
                    toast.error(first);
                }
            },
            onFinish: () => {
                busy = false;
            },
        },
    );
}

export function undo(team: string): void {
    if (busy) {
        return;
    }

    const step = findLast(undoStack, team);

    if (!step) {
        toast.info(t("Nothing to undo."));

        return;
    }

    send(step, "undo", () => redoStack.push(step));
}

export function redo(team: string): void {
    if (busy) {
        return;
    }

    const step = findLast(redoStack, team);

    if (!step) {
        toast.info(t("Nothing to redo."));

        return;
    }

    send(step, "redo", () => undoStack.push(step));
}

/** Take the last step of the household off the stack. */
function findLast(stack: Step[], team: string): Step | undefined {
    for (let index = stack.length - 1; index >= 0; index--) {
        if (stack[index].team === team) {
            return stack.splice(index, 1)[0];
        }
    }

    return undefined;
}

/**
 * Cmd/Ctrl+Z undoes, Shift+Cmd/Ctrl+Z (or Ctrl+Y) redoes. Typing in a field
 * keeps the browser's own text undo.
 */
export function handleUndoShortcut(event: KeyboardEvent, team: string): void {
    if (!(event.metaKey || event.ctrlKey) || event.altKey) {
        return;
    }

    const key = event.key.toLowerCase();
    const isRedo =
        (key === "z" && event.shiftKey) || (key === "y" && !event.shiftKey);
    const isUndo = key === "z" && !event.shiftKey;

    if (!isUndo && !isRedo) {
        return;
    }

    const target = event.target as HTMLElement | null;

    if (target?.closest('input, textarea, select, [contenteditable="true"]')) {
        return;
    }

    event.preventDefault();

    if (isRedo) {
        redo(team);
    } else {
        undo(team);
    }
}
