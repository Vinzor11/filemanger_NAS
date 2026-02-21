import { router } from '@inertiajs/react';
import { FileText, FolderOpen, Search, XCircle } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { cn } from '@/lib/utils';

type SearchResultFolder = {
    public_id: string;
    name: string;
    path: string;
    target_url: string;
};

type SearchResultFile = {
    public_id: string;
    name: string;
    path: string;
    folder_public_id: string | null;
    target_url: string;
};

type SearchSuggestionResponse = {
    data?: {
        folders?: SearchResultFolder[];
        files?: SearchResultFile[];
    };
};

type SidebarExplorerSearchProps = {
    mode?: 'sidebar' | 'header';
    className?: string;
};

const RECENT_SEARCHES_STORAGE_KEY = 'explorer-recent-searches';
const MAX_RECENT_SEARCHES = 8;

function normalizeSearchValue(value: string): string {
    return value.toLowerCase().replace(/[^a-z0-9]+/g, '');
}

function fuzzyScore(needle: string, candidate: string): number {
    const query = normalizeSearchValue(needle);
    const text = normalizeSearchValue(candidate);

    if (!query || !text) {
        return 0;
    }

    const containsIndex = text.indexOf(query);
    if (containsIndex >= 0) {
        return Math.max(
            1,
            1200 - containsIndex * 6 - Math.abs(text.length - query.length),
        );
    }

    let cursor = 0;
    let gapPenalty = 0;

    for (const character of query) {
        const foundAt = text.indexOf(character, cursor);
        if (foundAt < 0) {
            return 0;
        }

        gapPenalty += Math.max(0, foundAt - cursor);
        cursor = foundAt + 1;
    }

    return Math.max(1, 600 - gapPenalty - Math.abs(text.length - query.length));
}

function loadRecentSearches(): string[] {
    if (typeof window === 'undefined') {
        return [];
    }

    try {
        const raw = window.localStorage.getItem(RECENT_SEARCHES_STORAGE_KEY);
        if (!raw) {
            return [];
        }

        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            return [];
        }

        const sanitized: string[] = [];
        for (const value of parsed) {
            if (typeof value !== 'string') {
                continue;
            }

            const trimmed = value.trim();
            if (trimmed === '') {
                continue;
            }

            if (
                sanitized.some(
                    (existing) =>
                        existing.toLocaleLowerCase() ===
                        trimmed.toLocaleLowerCase(),
                )
            ) {
                continue;
            }

            sanitized.push(trimmed);
            if (sanitized.length >= MAX_RECENT_SEARCHES) {
                break;
            }
        }

        return sanitized;
    } catch {
        return [];
    }
}

function persistRecentSearches(queries: string[]): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(
        RECENT_SEARCHES_STORAGE_KEY,
        JSON.stringify(queries),
    );
}

export function SidebarExplorerSearch({
    mode = 'sidebar',
    className,
}: SidebarExplorerSearchProps) {
    const [query, setQuery] = useState('');
    const [isOpen, setIsOpen] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [folderResults, setFolderResults] = useState<SearchResultFolder[]>([]);
    const [fileResults, setFileResults] = useState<SearchResultFile[]>([]);
    const [recentSearches, setRecentSearches] = useState<string[]>([]);
    const containerRef = useRef<HTMLDivElement | null>(null);
    const trimmedQuery = query.trim();

    useEffect(() => {
        setRecentSearches(loadRecentSearches());
    }, []);

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        const handlePointerDown = (event: PointerEvent) => {
            if (!containerRef.current) {
                return;
            }

            if (containerRef.current.contains(event.target as Node)) {
                return;
            }

            setIsOpen(false);
        };

        window.addEventListener('pointerdown', handlePointerDown);

        return () => {
            window.removeEventListener('pointerdown', handlePointerDown);
        };
    }, [isOpen]);

    const saveRecentSearch = useCallback((value: string) => {
        const nextValue = value.trim();
        if (nextValue === '') {
            return;
        }

        setRecentSearches((current) => {
            const filtered = current.filter(
                (item) =>
                    item.toLocaleLowerCase() !== nextValue.toLocaleLowerCase(),
            );
            const next = [nextValue, ...filtered].slice(0, MAX_RECENT_SEARCHES);
            persistRecentSearches(next);

            return next;
        });
    }, []);

    const clearRecentSearches = useCallback(() => {
        setRecentSearches([]);
        persistRecentSearches([]);
    }, []);

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        if (!trimmedQuery) {
            setFolderResults([]);
            setFileResults([]);
            setIsLoading(false);
            return;
        }

        const controller = new AbortController();
        setIsLoading(true);

        const timeoutId = window.setTimeout(async () => {
            const params = new URLSearchParams();
            params.set('q', trimmedQuery);
            params.set('limit', '10');

            try {
                const response = await fetch(
                    `/search/suggestions?${params.toString()}`,
                    {
                        signal: controller.signal,
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    },
                );

                if (!response.ok) {
                    throw new Error(`Search failed with status ${response.status}`);
                }

                const payload = (await response.json()) as SearchSuggestionResponse;
                setFolderResults(payload.data?.folders ?? []);
                setFileResults(payload.data?.files ?? []);
            } catch {
                if (!controller.signal.aborted) {
                    setFolderResults([]);
                    setFileResults([]);
                }
            } finally {
                if (!controller.signal.aborted) {
                    setIsLoading(false);
                }
            }
        }, 150);

        return () => {
            controller.abort();
            window.clearTimeout(timeoutId);
        };
    }, [isOpen, trimmedQuery]);

    const rankedFolderResults = useMemo(() => {
        if (!trimmedQuery) {
            return folderResults;
        }

        return [...folderResults]
            .map((folderResult) => ({
                folderResult,
                score: fuzzyScore(
                    trimmedQuery,
                    `${folderResult.name} ${folderResult.path}`,
                ),
            }))
            .filter((item) => item.score > 0)
            .sort((a, b) => b.score - a.score)
            .map((item) => item.folderResult);
    }, [folderResults, trimmedQuery]);

    const rankedFileResults = useMemo(() => {
        if (!trimmedQuery) {
            return fileResults;
        }

        return [...fileResults]
            .map((fileResult) => ({
                fileResult,
                score: fuzzyScore(trimmedQuery, `${fileResult.name} ${fileResult.path}`),
            }))
            .filter((item) => item.score > 0)
            .sort((a, b) => b.score - a.score)
            .map((item) => item.fileResult);
    }, [fileResults, trimmedQuery]);

    const navigateTo = useCallback(
        (targetUrl: string) => {
            saveRecentSearch(trimmedQuery);
            setIsOpen(false);
            router.visit(targetUrl, {
                method: 'get',
                preserveScroll: true,
                preserveState: true,
            });
        },
        [saveRecentSearch, trimmedQuery],
    );

    const hasSuggestions =
        rankedFolderResults.length > 0 || rankedFileResults.length > 0;

    return (
        <div
            ref={containerRef}
            className={cn(
                mode === 'header'
                    ? 'w-full max-w-3xl'
                    : 'px-2 pb-1 group-data-[collapsible=icon]:hidden',
                className,
            )}
        >
            <Command
                shouldFilter={false}
                className={cn(
                    mode === 'header'
                        ? 'relative overflow-visible rounded-lg border border-border/80 bg-background text-foreground shadow-xs [&_[data-slot=command-input-wrapper]]:h-11 [&_[data-slot=command-input-wrapper]]:rounded-lg [&_[data-slot=command-input-wrapper]]:border-0 [&_[data-slot=command-input-wrapper]]:px-4 [&_[data-slot=command-input]]:text-base'
                        : 'rounded-md border border-sidebar-border/60 bg-sidebar/10 text-sidebar-foreground [&_[data-slot=command-empty]]:text-sidebar-foreground/70 [&_[data-slot=command-group-heading]]:text-sidebar-foreground/75 [&_[data-slot=command-input-wrapper]]:border-sidebar-border/60 [&_[data-slot=command-input-wrapper]]:bg-transparent [&_[data-slot=command-input]]:text-sidebar-foreground [&_[data-slot=command-input]]:placeholder:text-sidebar-foreground/65 [&_[data-slot=command-item][data-selected=true]]:bg-sidebar-accent [&_[data-slot=command-item][data-selected=true]]:text-sidebar-foreground',
                )}
            >
                <CommandInput
                    value={query}
                    onValueChange={setQuery}
                    placeholder="Search across all files and folders"
                    onFocus={() => setIsOpen(true)}
                    onMouseDown={() => setIsOpen(true)}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            setIsOpen(false);
                        }
                    }}
                />

                {isOpen ? (
                    <CommandList
                        className={cn(
                            mode === 'header'
                                ? 'absolute top-full right-0 left-0 z-50 mt-2 max-h-[460px] rounded-lg border border-border bg-popover p-1 shadow-lg'
                                : undefined,
                        )}
                    >
                        {!trimmedQuery && recentSearches.length > 0 ? (
                            <CommandGroup heading="Recent Searches">
                                {recentSearches.map((recentSearch) => (
                                    <CommandItem
                                        key={`recent:${recentSearch}`}
                                        value={`recent:${recentSearch}`}
                                        onSelect={() => {
                                            setQuery(recentSearch);
                                        }}
                                    >
                                        <Search className="size-4" />
                                        <span className="truncate">{recentSearch}</span>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        ) : null}

                        {trimmedQuery ? (
                            <>
                                {isLoading ? (
                                    <div className="px-2 py-3 text-xs text-muted-foreground">
                                        Searching...
                                    </div>
                                ) : null}

                                {!isLoading && rankedFolderResults.length > 0 ? (
                                    <CommandGroup heading="Folder Suggestions">
                                        {rankedFolderResults.map((folderResult) => (
                                            <CommandItem
                                                key={`folder:${folderResult.public_id}`}
                                                value={`folder:${folderResult.public_id}`}
                                                onSelect={() => {
                                                    navigateTo(folderResult.target_url);
                                                }}
                                            >
                                                <FolderOpen className="size-4" />
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {folderResult.name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {folderResult.path}
                                                    </p>
                                                </div>
                                            </CommandItem>
                                        ))}
                                    </CommandGroup>
                                ) : null}

                                {!isLoading && rankedFileResults.length > 0 ? (
                                    <CommandGroup heading="File Suggestions">
                                        {rankedFileResults.map((fileResult) => (
                                            <CommandItem
                                                key={`file:${fileResult.public_id}`}
                                                value={`file:${fileResult.public_id}`}
                                                onSelect={() => {
                                                    navigateTo(fileResult.target_url);
                                                }}
                                            >
                                                <FileText className="size-4" />
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {fileResult.name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {fileResult.path}
                                                    </p>
                                                </div>
                                            </CommandItem>
                                        ))}
                                    </CommandGroup>
                                ) : null}
                            </>
                        ) : null}

                        {!trimmedQuery && recentSearches.length > 0 ? (
                            <CommandGroup heading="Actions">
                                <CommandItem
                                    value="action-clear-recent-searches"
                                    onSelect={() => {
                                        clearRecentSearches();
                                    }}
                                >
                                    <XCircle className="size-4" />
                                    <span>Clear recent searches</span>
                                </CommandItem>
                            </CommandGroup>
                        ) : null}

                        {!trimmedQuery && recentSearches.length === 0 ? (
                            <div className="px-2 py-3 text-xs text-muted-foreground">
                                Start typing to get suggestions across My Files,
                                Department Files, Shared With Me, and nested
                                folders.
                            </div>
                        ) : null}

                        {!isLoading && trimmedQuery && !hasSuggestions ? (
                            <CommandEmpty>No suggestions found.</CommandEmpty>
                        ) : null}
                    </CommandList>
                ) : null}
            </Command>
        </div>
    );
}
