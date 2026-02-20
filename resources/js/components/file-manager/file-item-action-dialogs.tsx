import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import type { FileRow, FolderRow } from '@/components/file-manager/file-table';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

export type MoveTarget =
    | { kind: 'file'; file: FileRow }
    | { kind: 'folder'; folder: FolderRow }
    | { kind: 'bulk-files'; files: FileRow[] };

export type DetailsTarget =
    | { kind: 'file'; file: FileRow }
    | { kind: 'folder'; folder: FolderRow };

type FileVersionEntry = {
    id: number;
    version_no: number;
    size_bytes: number;
    created_at: string;
    creator?: {
        name?: string | null;
        email?: string | null;
    } | null;
};

type ActivityEntry = {
    id: number;
    action: string;
    created_at: string;
    actor?: {
        name?: string | null;
        email?: string | null;
    } | null;
};

type FileItemActionDialogsProps = {
    folders: FolderRow[];
    moveTarget: MoveTarget | null;
    onMoveTargetChange: (target: MoveTarget | null) => void;
    detailsTarget: DetailsTarget | null;
    onDetailsTargetChange: (target: DetailsTarget | null) => void;
};

type DetailsView = 'versions' | 'activities';

function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '-';
    }

    return new Date(value).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function displayActivityAction(action: string): string {
    const normalized = action.replace(/[._]/g, ' ');

    return normalized.replace(/\b\w/g, (chunk) => chunk.toUpperCase());
}

function firstErrorMessage(
    errors: Record<string, string | string[]>,
): string | null {
    for (const message of Object.values(errors)) {
        if (Array.isArray(message)) {
            if (message.length > 0 && String(message[0]).trim()) {
                return String(message[0]).trim();
            }
            continue;
        }

        const nextMessage = message.trim();
        if (nextMessage) {
            return nextMessage;
        }
    }

    return null;
}

async function fetchJsonData<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        let errorMessage = `Request failed (${response.status}).`;
        try {
            const payload = (await response.json()) as {
                message?: string;
                errors?: Record<string, string | string[]>;
            };
            if (typeof payload.message === 'string' && payload.message.trim()) {
                errorMessage = payload.message.trim();
            } else if (payload.errors) {
                for (const value of Object.values(payload.errors)) {
                    if (Array.isArray(value) && value.length > 0) {
                        errorMessage = String(value[0]);
                        break;
                    }
                    if (typeof value === 'string' && value.trim() !== '') {
                        errorMessage = value.trim();
                        break;
                    }
                }
            }
        } catch {
            // Ignore parse errors and fallback to status-based message.
        }

        throw new Error(errorMessage);
    }

    const payload = (await response.json()) as { data?: T };
    if (!('data' in payload)) {
        throw new Error('Unexpected response payload.');
    }

    return payload.data as T;
}

export function FileItemActionDialogs({
    folders,
    moveTarget,
    onMoveTargetChange,
    detailsTarget,
    onDetailsTargetChange,
}: FileItemActionDialogsProps) {
    const [selectedDestinationFolderId, setSelectedDestinationFolderId] =
        useState('');
    const [isMoveProcessing, setIsMoveProcessing] = useState(false);
    const [moveError, setMoveError] = useState<string | null>(null);
    const [detailsVersions, setDetailsVersions] = useState<FileVersionEntry[]>(
        [],
    );
    const [detailsActivities, setDetailsActivities] = useState<
        ActivityEntry[]
    >([]);
    const [detailsView, setDetailsView] = useState<DetailsView>('versions');
    const [isVersionsLoading, setIsVersionsLoading] = useState(false);
    const [isActivitiesLoading, setIsActivitiesLoading] = useState(false);
    const [versionsLoaded, setVersionsLoaded] = useState(false);
    const [activitiesLoaded, setActivitiesLoaded] = useState(false);
    const [detailsError, setDetailsError] = useState<string | null>(null);

    const availableMoveDestinations = useMemo(() => {
        if (!moveTarget) {
            return folders;
        }

        if (moveTarget.kind === 'folder') {
            return folders.filter(
                (folder) => folder.public_id !== moveTarget.folder.public_id,
            );
        }

        return folders;
    }, [folders, moveTarget]);
    const detailsLabel = useMemo(() => {
        if (!detailsTarget) {
            return '';
        }

        return detailsTarget.kind === 'file'
            ? detailsTarget.file.original_name
            : detailsTarget.folder.name;
    }, [detailsTarget]);
    const moveDescription = useMemo(() => {
        if (!moveTarget) {
            return 'Select the destination folder.';
        }

        if (moveTarget.kind === 'bulk-files') {
            return `Move ${moveTarget.files.length} selected file(s) to the destination folder.`;
        }

        return 'Select the destination folder.';
    }, [moveTarget]);

    useEffect(() => {
        setMoveError(null);
        setSelectedDestinationFolderId(
            availableMoveDestinations[0]?.public_id ?? '',
        );
    }, [availableMoveDestinations]);

    useEffect(() => {
        if (!detailsTarget) {
            setDetailsVersions([]);
            setDetailsActivities([]);
            setDetailsError(null);
            setVersionsLoaded(false);
            setActivitiesLoaded(false);
            setIsVersionsLoading(false);
            setIsActivitiesLoading(false);
            setDetailsView('versions');

            return;
        }
        setDetailsVersions([]);
        setDetailsActivities([]);
        setDetailsError(null);
        setVersionsLoaded(false);
        setActivitiesLoaded(false);
        setIsVersionsLoading(false);
        setIsActivitiesLoading(false);
        setDetailsView(detailsTarget.kind === 'file' ? 'versions' : 'activities');
    }, [detailsTarget]);

    useEffect(() => {
        if (
            !detailsTarget ||
            detailsTarget.kind !== 'file' ||
            detailsView !== 'versions' ||
            versionsLoaded
        ) {
            return;
        }

        let isMounted = true;

        const loadVersions = async () => {
            setIsVersionsLoading(true);
            setDetailsError(null);

            try {
                const versions = await fetchJsonData<FileVersionEntry[]>(
                    `/files/${detailsTarget.file.public_id}/versions`,
                );
                if (!isMounted) {
                    return;
                }
                setDetailsVersions(versions);
                setVersionsLoaded(true);
            } catch (error) {
                if (!isMounted) {
                    return;
                }

                setDetailsError(
                    error instanceof Error
                        ? error.message
                        : 'Unable to load item details.',
                );
            } finally {
                if (isMounted) {
                    setIsVersionsLoading(false);
                }
            }
        };

        void loadVersions();

        return () => {
            isMounted = false;
        };
    }, [detailsTarget, detailsView, versionsLoaded]);

    useEffect(() => {
        if (!detailsTarget || detailsView !== 'activities' || activitiesLoaded) {
            return;
        }

        let isMounted = true;

        const loadActivities = async () => {
            setIsActivitiesLoading(true);
            setDetailsError(null);

            try {
                const activities = await fetchJsonData<ActivityEntry[]>(
                    detailsTarget.kind === 'file'
                        ? `/files/${detailsTarget.file.public_id}/activities`
                        : `/folders/${detailsTarget.folder.public_id}/activities`,
                );
                if (!isMounted) {
                    return;
                }
                setDetailsActivities(activities);
                setActivitiesLoaded(true);
            } catch (error) {
                if (!isMounted) {
                    return;
                }

                setDetailsError(
                    error instanceof Error
                        ? error.message
                        : 'Unable to load item details.',
                );
            } finally {
                if (isMounted) {
                    setIsActivitiesLoading(false);
                }
            }
        };

        void loadActivities();

        return () => {
            isMounted = false;
        };
    }, [activitiesLoaded, detailsTarget, detailsView]);

    return (
        <>
            <Dialog
                open={moveTarget !== null}
                onOpenChange={(nextOpen) => {
                    if (!nextOpen) {
                        onMoveTargetChange(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Move to</DialogTitle>
                        <DialogDescription>{moveDescription}</DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (
                                !moveTarget ||
                                !selectedDestinationFolderId ||
                                isMoveProcessing
                            ) {
                                return;
                            }

                            setMoveError(null);
                            setIsMoveProcessing(true);

                            if (moveTarget.kind === 'bulk-files') {
                                router.post(
                                    '/selection/move',
                                    {
                                        files: moveTarget.files.map(
                                            (file) => file.public_id,
                                        ),
                                        destination_folder_id:
                                            selectedDestinationFolderId,
                                        silent: true,
                                    },
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                        headers: {
                                            'X-Idempotency-Key':
                                                crypto.randomUUID(),
                                        },
                                        onSuccess: () => {
                                            onMoveTargetChange(null);
                                        },
                                        onError: (errors) => {
                                            setMoveError(
                                                firstErrorMessage(
                                                    errors as Record<
                                                        string,
                                                        string | string[]
                                                    >,
                                                ) ??
                                                    'Unable to move selected files.',
                                            );
                                        },
                                        onFinish: () => {
                                            setIsMoveProcessing(false);
                                        },
                                    },
                                );

                                return;
                            }

                            router.patch(
                                moveTarget.kind === 'file'
                                    ? `/files/${moveTarget.file.public_id}/move`
                                    : `/folders/${moveTarget.folder.public_id}/move`,
                                {
                                    destination_folder_id:
                                        selectedDestinationFolderId,
                                },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        onMoveTargetChange(null);
                                    },
                                    onError: (errors) => {
                                        setMoveError(
                                            firstErrorMessage(
                                                errors as Record<
                                                    string,
                                                    string | string[]
                                                >,
                                            ) ??
                                                'Unable to move selected item.',
                                        );
                                    },
                                    onFinish: () => {
                                        setIsMoveProcessing(false);
                                    },
                                },
                            );
                        }}
                    >
                        <div className="space-y-2">
                            <Label htmlFor="destination_folder_id">
                                Destination folder
                            </Label>
                            <select
                                id="destination_folder_id"
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                                value={selectedDestinationFolderId}
                                onChange={(event) =>
                                    setSelectedDestinationFolderId(
                                        event.target.value,
                                    )
                                }
                                disabled={
                                    !availableMoveDestinations.length ||
                                    isMoveProcessing
                                }
                                required
                            >
                                {availableMoveDestinations.length ? (
                                    availableMoveDestinations.map((folder) => (
                                        <option
                                            key={folder.public_id}
                                            value={folder.public_id}
                                        >
                                            {folder.name}
                                        </option>
                                    ))
                                ) : (
                                    <option value="">
                                        No destination folders available
                                    </option>
                                )}
                            </select>
                        </div>

                        {moveError ? (
                            <p className="text-sm text-warning">{moveError}</p>
                        ) : null}

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => onMoveTargetChange(null)}
                                disabled={isMoveProcessing}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    isMoveProcessing ||
                                    !selectedDestinationFolderId ||
                                    !availableMoveDestinations.length
                                }
                            >
                                Move
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={detailsTarget !== null}
                onOpenChange={(nextOpen) => {
                    if (!nextOpen) {
                        onDetailsTargetChange(null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-6xl">
                    <DialogHeader>
                        <DialogTitle>Details</DialogTitle>
                        <DialogDescription className="truncate">
                            {detailsLabel}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="max-h-[72vh] overflow-y-auto pr-1">
                        <div className="grid gap-4 md:grid-cols-3">
                            <section className="space-y-3 rounded-lg border bg-background p-4">
                                <h3 className="text-sm font-semibold">
                                    Information
                                </h3>
                                {detailsTarget ? (
                                    <dl className="space-y-2 text-sm">
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Type
                                            </dt>
                                            <dd className="font-medium text-foreground">
                                                {detailsTarget.kind === 'file'
                                                    ? 'File'
                                                    : 'Folder'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Name
                                            </dt>
                                            <dd className="font-medium text-foreground">
                                                {detailsTarget.kind === 'file'
                                                    ? detailsTarget.file
                                                          .original_name
                                                    : detailsTarget.folder.name}
                                            </dd>
                                        </div>
                                        {detailsTarget.kind === 'file' ? (
                                            <>
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">
                                                        Size
                                                    </dt>
                                                    <dd className="font-medium text-foreground">
                                                        {formatBytes(
                                                            detailsTarget.file
                                                                .size_bytes,
                                                        )}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">
                                                        Extension
                                                    </dt>
                                                    <dd className="font-medium text-foreground">
                                                        {detailsTarget.file.extension?.toUpperCase() ??
                                                            '-'}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">
                                                        Location
                                                    </dt>
                                                    <dd className="font-medium text-foreground">
                                                        {detailsTarget.file
                                                            .folder?.name ??
                                                            'Unknown folder'}
                                                    </dd>
                                                </div>
                                            </>
                                        ) : (
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Path
                                                </dt>
                                                <dd className="font-medium text-foreground">
                                                    {detailsTarget.folder.path ??
                                                        detailsTarget.folder
                                                            .name}
                                                </dd>
                                            </div>
                                        )}
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Created
                                            </dt>
                                            <dd className="font-medium text-foreground">
                                                {formatDateTime(
                                                    detailsTarget.kind ===
                                                        'file'
                                                        ? detailsTarget.file
                                                              .created_at
                                                        : detailsTarget.folder
                                                              .created_at,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Last updated
                                            </dt>
                                            <dd className="font-medium text-foreground">
                                                {formatDateTime(
                                                    detailsTarget.kind ===
                                                        'file'
                                                        ? (detailsTarget.file
                                                              .updated_at ??
                                                          detailsTarget.file
                                                              .created_at)
                                                        : (detailsTarget.folder
                                                              .updated_at ??
                                                          detailsTarget.folder
                                                              .created_at),
                                                )}
                                            </dd>
                                        </div>
                                    </dl>
                                ) : null}
                            </section>
                            <section className="space-y-3 rounded-lg border bg-background p-4 md:col-span-2">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h3 className="text-sm font-semibold">
                                        {detailsView === 'versions'
                                            ? 'Versions'
                                            : 'Activities'}
                                    </h3>
                                    {detailsTarget?.kind === 'file' ? (
                                        <div className="inline-flex items-center gap-1 rounded-md border border-border/70 p-1">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant={
                                                    detailsView === 'versions'
                                                        ? 'secondary'
                                                        : 'ghost'
                                                }
                                                onClick={() => {
                                                    setDetailsError(null);
                                                    setDetailsView('versions');
                                                }}
                                            >
                                                Versions
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant={
                                                    detailsView === 'activities'
                                                        ? 'secondary'
                                                        : 'ghost'
                                                }
                                                onClick={() => {
                                                    setDetailsError(null);
                                                    setDetailsView('activities');
                                                }}
                                            >
                                                Activity
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>

                                {detailsView === 'versions' ? (
                                    detailsTarget?.kind === 'folder' ? (
                                        <p className="text-sm text-muted-foreground">
                                            Versions are available for files only.
                                        </p>
                                    ) : isVersionsLoading ? (
                                        <p className="text-sm text-muted-foreground">
                                            Loading versions...
                                        </p>
                                    ) : detailsVersions.length ? (
                                        <ul className="space-y-2">
                                            {detailsVersions.map((version) => (
                                                <li
                                                    key={version.id}
                                                    className="rounded-md border px-3 py-2 text-sm"
                                                >
                                                    <p className="font-medium">
                                                        Version {version.version_no}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {formatBytes(
                                                            version.size_bytes,
                                                        )}{' '}
                                                        -{' '}
                                                        {formatDateTime(
                                                            version.created_at,
                                                        )}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {version.creator?.name ??
                                                            version.creator
                                                                ?.email ??
                                                            'Unknown user'}
                                                    </p>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            No versions found.
                                        </p>
                                    )
                                ) : isActivitiesLoading ? (
                                    <p className="text-sm text-muted-foreground">
                                        Loading activities...
                                    </p>
                                ) : detailsActivities.length ? (
                                    <ul className="space-y-2">
                                        {detailsActivities.map((activity) => (
                                            <li
                                                key={activity.id}
                                                className="rounded-md border px-3 py-2 text-sm"
                                            >
                                                <p className="font-medium">
                                                    {displayActivityAction(
                                                        activity.action,
                                                    )}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {activity.actor?.name ??
                                                        activity.actor
                                                            ?.email ??
                                                        'System'}{' '}
                                                    -{' '}
                                                    {formatDateTime(
                                                        activity.created_at,
                                                    )}
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No activities found.
                                    </p>
                                )}
                            </section>
                        </div>
                        {detailsError ? (
                            <p className="mt-3 text-sm text-warning">
                                {detailsError}
                            </p>
                        ) : null}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onDetailsTargetChange(null)}
                        >
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
