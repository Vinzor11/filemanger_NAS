import { useEffect, useState } from 'react';

export type FileLayoutMode = 'table' | 'context';

function normalizeMode(value: string | null, fallback: FileLayoutMode): FileLayoutMode {
    return value === 'context' || value === 'table' ? value : fallback;
}

export function useFileLayoutMode(
    storageKey: string,
    defaultMode: FileLayoutMode = 'table',
) {
    const [layoutMode, setLayoutMode] = useState<FileLayoutMode>(() => {
        if (typeof window === 'undefined') {
            return defaultMode;
        }

        return normalizeMode(window.localStorage.getItem(storageKey), defaultMode);
    });

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        window.localStorage.setItem(storageKey, layoutMode);
    }, [layoutMode, storageKey]);

    return [layoutMode, setLayoutMode] as const;
}
