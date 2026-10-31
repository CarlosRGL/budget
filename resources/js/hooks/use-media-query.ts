import { useCallback, useSyncExternalStore } from 'react';

/**
 * Whether a CSS media query matches, kept in sync with the window size.
 */
export function useMediaQuery(query: string): boolean {
    const subscribe = useCallback(
        (callback: () => void) => {
            const list = window.matchMedia(query);
            list.addEventListener('change', callback);

            return () => list.removeEventListener('change', callback);
        },
        [query],
    );

    return useSyncExternalStore(
        subscribe,
        () => window.matchMedia(query).matches,
        () => false,
    );
}
