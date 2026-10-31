import { usePage } from '@inertiajs/react';

/**
 * Whether the member only reads the household's budget, in the simple view.
 */
export function useIsViewer(): boolean {
    return usePage().props.currentTeam?.role === 'viewer';
}
