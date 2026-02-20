import { Link, router } from '@inertiajs/react';
import {
    ArrowUp,
    ArrowUpDown,
    Download,
    Eye,
    FileArchive,
    FileAudio,
    FileCode2,
    FileImage,
    FileSpreadsheet,
    FileText,
    FileType,
    FileVideo,
    Folder,
    FolderInput,
    Info,
    MoreVertical,
    Pencil,
    RotateCcw,
    Share2,
    Trash2,
    Upload,
    X,
} from 'lucide-react';
import {
    type MouseEvent,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { TableCardSkeleton } from '@/components/ui/page-loading-skeletons';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export type SharingRecipient = {
    type?: 'user' | 'department' | null;
    public_id?: string | null;
    name?: string | null;
    email?: string | null;
};

export type SharingInfo = {
    is_shared?: boolean;
    shared_with?: SharingRecipient[];
} | null;

export type FileRow = {
    public_id: string;
    original_name: string;
    extension: string | null;
    mime_type?: string | null;
    size_bytes: number;
    visibility?: 'private' | 'department' | 'shared';
    created_at: string;
    updated_at?: string | null;
    deleted_at?: string | null;
    folder?: {
        public_id: string;
        name: string;
        path?: string | null;
        visibility?: 'private' | 'department' | 'shared';
    } | null;
    owner?: {
        email: string | null;
        name?: string | null;
        avatar?: string | null;
    } | null;
    access?: {
        can_view?: boolean;
        can_download?: boolean;
        can_edit?: boolean;
        can_delete?: boolean;
    } | null;
    sharing?: SharingInfo;
};

export type FolderRow = {
    public_id: string;
    name: string;
    path?: string | null;
    visibility?: 'private' | 'department' | 'shared';
    trashed_files_count?: number;
    created_at?: string | null;
    updated_at?: string | null;
    deleted_at?: string | null;
    owner?: {
        email: string | null;
        name?: string | null;
        avatar?: string | null;
    } | null;
    access?: {
        can_view?: boolean;
        can_upload?: boolean;
        can_edit?: boolean;
        can_delete?: boolean;
    } | null;
    sharing?: SharingInfo;
};

type CurrentUser = {
    email?: string | null;
    name?: string | null;
    avatar?: string | null;
} | null;

type FileTableProps = {
    files: FileRow[];
    folders?: FolderRow[];
    currentUser?: CurrentUser;
    onDelete?: (file: FileRow) => void;
    onReplace?: (file: FileRow) => void;
    onRename?: (file: FileRow) => void;
    onDeleteFolder?: (folder: FolderRow) => void;
    onShare?: (file: FileRow) => void;
    onShareFolder?: (folder: FolderRow) => void;
    onRestoreFile?: (file: FileRow) => void;
    onRestoreFolder?: (folder: FolderRow) => void;
    onPurgeFile?: (file: FileRow) => void;
    onPurgeFolder?: (folder: FolderRow) => void;
    onOpenFolder?: (folder: FolderRow) => void;
    onMoveFile?: (file: FileRow) => void;
    onMoveFolder?: (folder: FolderRow) => void;
    onPreviewFile?: (file: FileRow) => void;
    onPreviewFolder?: (folder: FolderRow) => void;
    onDetailsFile?: (file: FileRow) => void;
    onDetailsFolder?: (folder: FolderRow) => void;
    onRenameFolder?: (folder: FolderRow) => void;
    onRemoveFileAccess?: (file: FileRow) => void;
    onRemoveFolderAccess?: (folder: FolderRow) => void;
    onBulkDownload?: (selection: {
        files: FileRow[];
        folders: FolderRow[];
    }) => Promise<void> | void;
    onBulkShare?: (selection: {
        files: FileRow[];
        folders: FolderRow[];
    }) => Promise<void> | void;
    onBulkMove?: (selection: {
        files: FileRow[];
        folders: FolderRow[];
    }) => Promise<void> | void;
    onBulkTrash?: (selection: {
        files: FileRow[];
        folders: FolderRow[];
    }) => Promise<void> | void;
    onBulkRestore?: (selection: {
        files: FileRow[];
        folders: FolderRow[];
    }) => Promise<void> | void;
    onBulkPurge?: (selection: {
        files: FileRow[];
        folders: FolderRow[];
    }) => Promise<void> | void;
    showSharingMarker?: boolean;
    loading?: boolean;
    emptyMessage?: string;
    viewMode?: 'default' | 'trash';
};

const IMAGE_EXTENSIONS = new Set([
    'png',
    'jpg',
    'jpeg',
    'gif',
    'webp',
    'bmp',
    'svg',
    'heic',
    'heif',
    'tif',
    'tiff',
]);
const VIDEO_EXTENSIONS = new Set([
    'mp4',
    'mov',
    'avi',
    'mkv',
    'webm',
    'wmv',
    'm4v',
    'mpeg',
    'mpg',
]);
const AUDIO_EXTENSIONS = new Set([
    'mp3',
    'wav',
    'ogg',
    'flac',
    'aac',
    'm4a',
    'wma',
]);
const ARCHIVE_EXTENSIONS = new Set([
    'zip',
    'rar',
    '7z',
    'tar',
    'gz',
    'bz2',
    'xz',
    'tgz',
]);
const SPREADSHEET_EXTENSIONS = new Set([
    'xls',
    'xlsx',
    'csv',
    'ods',
    'tsv',
]);
const CODE_EXTENSIONS = new Set([
    'js',
    'jsx',
    'ts',
    'tsx',
    'json',
    'xml',
    'yaml',
    'yml',
    'html',
    'css',
    'scss',
    'php',
    'py',
    'java',
    'c',
    'cpp',
    'h',
    'hpp',
    'go',
    'rs',
    'rb',
    'sh',
    'sql',
    'md',
]);

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function formatDate(value: string | null | undefined): string {
    if (!value) return '-';

    return new Date(value).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function formatTrashedFileCount(value: number | null | undefined): string {
    const count = value ?? 0;

    return `${count} file${count === 1 ? '' : 's'}`;
}

function rootLocationLabel(
    visibility: 'private' | 'department' | 'shared' | null | undefined,
): string {
    return visibility === 'department' ? 'Department Files' : 'My Files';
}

function folderOriginalLocation(folder: FolderRow): string {
    const path = (folder.path ?? '').trim();
    if (!path) {
        return rootLocationLabel(folder.visibility);
    }

    const parts = path.split('/').filter(Boolean);
    if (parts.length <= 1) {
        return rootLocationLabel(folder.visibility);
    }

    return parts.slice(0, -1).join('/');
}

function fileOriginalLocation(file: FileRow): string {
    const folderPath = (file.folder?.path ?? '').trim();
    if (folderPath) {
        return folderPath;
    }

    return rootLocationLabel(file.folder?.visibility ?? file.visibility);
}

function ownerMatchesCurrentUser(
    ownerEmail: string | null | undefined,
    currentUser: CurrentUser,
): boolean {
    if (!ownerEmail || !currentUser?.email) {
        return false;
    }

    return ownerEmail.toLowerCase() === currentUser.email.toLowerCase();
}

function ownerLabel(
    ownerEmail: string | null | undefined,
    currentUser: CurrentUser,
): string {
    if (ownerMatchesCurrentUser(ownerEmail, currentUser)) {
        return 'me';
    }

    return ownerEmail ?? '-';
}

function toInitials(value: string): string {
    const chunks = value
        .split(/[\s@._-]+/)
        .map((chunk) => chunk.trim())
        .filter(Boolean);

    if (!chunks.length) {
        return 'U';
    }

    if (chunks.length === 1) {
        return chunks[0].slice(0, 2).toUpperCase();
    }

    return `${chunks[0][0]}${chunks[1][0]}`.toUpperCase();
}

function sharingRecipientLabel(recipient: SharingRecipient): string {
    const identity = (recipient.name ?? recipient.email ?? '').trim();

    if (recipient.type === 'department') {
        return identity ? `Department: ${identity}` : 'Department';
    }

    return identity || 'Unknown user';
}

function sharedWithSummary(recipients: string[]): string {
    if (recipients.length <= 2) {
        return recipients.join(', ');
    }

    return `${recipients[0]}, ${recipients[1]}, +${recipients.length - 2} more`;
}

function isInteractiveTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return Boolean(
        target.closest(
            'button,a,input,textarea,select,[role="menuitem"],[data-no-row-select="true"]',
        ),
    );
}

function previewKind(
    mimeType: string | null | undefined,
): 'image' | 'pdf' | 'none' {
    if (!mimeType) {
        return 'none';
    }

    if (mimeType.startsWith('image/')) {
        return 'image';
    }

    if (mimeType === 'application/pdf') {
        return 'pdf';
    }

    return 'none';
}

function fileExtension(file: FileRow): string {
    const explicitExtension = (file.extension ?? '').trim().toLowerCase();
    if (explicitExtension) {
        return explicitExtension;
    }

    const fromName = file.original_name.split('.').pop()?.trim().toLowerCase() ?? '';

    return fromName;
}

function fileIconNode(file: FileRow) {
    const mimeType = (file.mime_type ?? '').toLowerCase();
    const extension = fileExtension(file);

    if (mimeType.startsWith('image/') || IMAGE_EXTENSIONS.has(extension)) {
        return <FileImage className="size-5" />;
    }

    if (mimeType.startsWith('video/') || VIDEO_EXTENSIONS.has(extension)) {
        return <FileVideo className="size-5" />;
    }

    if (mimeType.startsWith('audio/') || AUDIO_EXTENSIONS.has(extension)) {
        return <FileAudio className="size-5" />;
    }

    if (
        mimeType === 'application/pdf' ||
        extension === 'pdf'
    ) {
        return <FileType className="size-5" />;
    }

    if (
        mimeType.includes('zip') ||
        mimeType.includes('compressed') ||
        mimeType.includes('tar') ||
        mimeType.includes('gzip') ||
        ARCHIVE_EXTENSIONS.has(extension)
    ) {
        return <FileArchive className="size-5" />;
    }

    if (
        mimeType.includes('spreadsheet') ||
        mimeType.includes('excel') ||
        mimeType.includes('csv') ||
        SPREADSHEET_EXTENSIONS.has(extension)
    ) {
        return <FileSpreadsheet className="size-5" />;
    }

    if (
        mimeType.includes('json') ||
        mimeType.includes('xml') ||
        mimeType.includes('javascript') ||
        mimeType.includes('typescript') ||
        mimeType.includes('x-php') ||
        mimeType.startsWith('text/x-') ||
        CODE_EXTENSIONS.has(extension)
    ) {
        return <FileCode2 className="size-5" />;
    }

    return <FileText className="size-5" />;
}

type OwnerCellProps = {
    ownerEmail?: string | null;
    currentUser?: CurrentUser;
    fallbackToCurrentUser?: boolean;
};

function OwnerCell({
    ownerEmail = null,
    currentUser = null,
    fallbackToCurrentUser = false,
}: OwnerCellProps) {
    const effectiveEmail =
        ownerEmail ??
        (fallbackToCurrentUser ? (currentUser?.email ?? null) : null);

    if (!effectiveEmail) {
        return <span className="text-muted-foreground">-</span>;
    }

    const isCurrentUser = ownerMatchesCurrentUser(effectiveEmail, currentUser);
    const label = ownerLabel(effectiveEmail, currentUser);
    const initialsSeed = isCurrentUser
        ? (currentUser?.name ?? currentUser?.email ?? effectiveEmail)
        : effectiveEmail;

    return (
        <div className="flex items-center gap-2.5">
            <Avatar className="size-7 border border-border/60">
                {isCurrentUser && currentUser?.avatar ? (
                    <AvatarImage src={currentUser.avatar} alt={label} />
                ) : null}
                <AvatarFallback className="bg-muted text-[10px] font-medium text-muted-foreground">
                    {toInitials(initialsSeed)}
                </AvatarFallback>
            </Avatar>
            <span className="truncate">{label}</span>
        </div>
    );
}

function SharingMarker({ sharing }: { sharing?: SharingInfo }) {
    if (!sharing?.is_shared) {
        return null;
    }

    const recipients = (sharing.shared_with ?? [])
        .map((recipient) => sharingRecipientLabel(recipient))
        .filter(Boolean);
    const detailLabel =
        recipients.length > 0 ? `Shared with ${recipients.join(', ')}` : 'Shared';
    const summaryLabel =
        recipients.length > 0 ? `with ${sharedWithSummary(recipients)}` : '';

    return (
        <div className="mt-1 flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
            <span className="inline-flex shrink-0 rounded-full border border-primary/30 bg-primary/10 px-1.5 py-0.5 font-medium text-primary">
                Shared
            </span>
            {summaryLabel ? (
                <span className="truncate" title={detailLabel}>
                    {summaryLabel}
                </span>
            ) : null}
        </div>
    );
}

type UnifiedRow =
    | { key: string; kind: 'folder'; value: FolderRow }
    | { key: string; kind: 'file'; value: FileRow };

function FileIconCell({
    file,
    enablePreview,
}: {
    file: FileRow;
    enablePreview: boolean;
}) {
    const icon = (
        <span className="inline-flex size-7 items-center justify-center rounded-md text-primary">
            {fileIconNode(file)}
        </span>
    );

    if (!enablePreview) {
        return icon;
    }

    const kind = previewKind(file.mime_type);
    const previewUrl = `/files/${file.public_id}/preview`;

    return (
        <Tooltip>
            <TooltipTrigger asChild>{icon}</TooltipTrigger>
            <TooltipContent
                side="right"
                className="w-72 rounded-lg border border-border bg-popover p-0 text-popover-foreground shadow-soft-sm"
            >
                <div className="space-y-1 border-b border-border/70 p-3">
                    <p className="truncate text-sm font-medium">
                        {file.original_name}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {file.mime_type ?? file.extension ?? 'file'}
                    </p>
                </div>
                <div className="h-44 w-full overflow-hidden bg-muted/30">
                    {kind === 'image' ? (
                        <img
                            src={previewUrl}
                            alt={file.original_name}
                            className="h-full w-full object-cover"
                            loading="lazy"
                        />
                    ) : kind === 'pdf' ? (
                        <iframe
                            title={`Preview ${file.original_name}`}
                            src={previewUrl}
                            className="h-full w-full border-0"
                        />
                    ) : (
                        <div className="flex h-full items-center justify-center px-4 text-center text-xs text-muted-foreground">
                            Preview is not available for this file type.
                        </div>
                    )}
                </div>
                <div className="flex items-center justify-between p-3 text-xs text-muted-foreground">
                    <span>{formatBytes(file.size_bytes)}</span>
                    <span>{file.extension?.toUpperCase() ?? 'FILE'}</span>
                </div>
            </TooltipContent>
        </Tooltip>
    );
}

export function FileTable({
    files,
    folders = [],
    currentUser = null,
    onDelete,
    onReplace,
    onRename,
    onDeleteFolder,
    onShare,
    onShareFolder,
    onRestoreFile,
    onRestoreFolder,
    onPurgeFile,
    onPurgeFolder,
    onOpenFolder,
    onMoveFile,
    onMoveFolder,
    onPreviewFile,
    onPreviewFolder,
    onDetailsFile,
    onDetailsFolder,
    onRenameFolder,
    onRemoveFileAccess,
    onRemoveFolderAccess,
    onBulkDownload,
    onBulkShare,
    onBulkMove,
    onBulkTrash,
    onBulkRestore,
    onBulkPurge,
    showSharingMarker = true,
    loading = false,
    emptyMessage = 'No files or folders found.',
    viewMode = 'default',
}: FileTableProps) {
    const rows: UnifiedRow[] = useMemo(
        () => [
            ...folders.map(
                (folder): UnifiedRow => ({
                    key: `folder:${folder.public_id}`,
                    kind: 'folder',
                    value: folder,
                }),
            ),
            ...files.map(
                (file): UnifiedRow => ({
                    key: `file:${file.public_id}`,
                    kind: 'file',
                    value: file,
                }),
            ),
        ],
        [files, folders],
    );
    const isTrashMode = viewMode === 'trash';
    const [selectedKeys, setSelectedKeys] = useState<string[]>([]);
    const availableKeySet = useMemo(
        () => new Set(rows.map((row) => row.key)),
        [rows],
    );
    const effectiveSelectedKeys = useMemo(
        () => selectedKeys.filter((itemKey) => availableKeySet.has(itemKey)),
        [availableKeySet, selectedKeys],
    );
    const selectedKeySet = useMemo(
        () => new Set(effectiveSelectedKeys),
        [effectiveSelectedKeys],
    );
    const selectedRows = useMemo(
        () => rows.filter((row) => selectedKeySet.has(row.key)),
        [rows, selectedKeySet],
    );
    const selectedFiles = useMemo(
        () =>
            selectedRows
                .filter(
                    (
                        row,
                    ): row is { key: string; kind: 'file'; value: FileRow } =>
                        row.kind === 'file',
                )
                .map((row) => row.value),
        [selectedRows],
    );
    const selectedFolders = useMemo(
        () =>
            selectedRows
                .filter(
                    (
                        row,
                    ): row is {
                        key: string;
                        kind: 'folder';
                        value: FolderRow;
                    } => row.kind === 'folder',
                )
                .map((row) => row.value),
        [selectedRows],
    );
    const rowKeys = useMemo(() => rows.map((row) => row.key), [rows]);
    const [isSelectionActionProcessing, setIsSelectionActionProcessing] =
        useState(false);
    const [selectionActionError, setSelectionActionError] = useState<
        string | null
    >(null);
    const selectionAllowsDelete =
        selectedFiles.every((file) => file.access?.can_delete !== false) &&
        selectedFolders.every((folder) => folder.access?.can_delete !== false);
    const selectionAllowsDownload = selectedFiles.every(
        (file) => file.access?.can_download !== false,
    );
    const selectionAllowsEdit = selectedFiles.every(
        (file) => file.access?.can_edit !== false,
    );
    const canBulkTrash =
        !isTrashMode &&
        typeof onBulkTrash === 'function' &&
        (selectedFiles.length > 0 || selectedFolders.length > 0) &&
        selectionAllowsDelete;
    const canBulkPurge =
        isTrashMode &&
        typeof onBulkPurge === 'function' &&
        (selectedFiles.length > 0 || selectedFolders.length > 0);
    const canBulkRestore =
        isTrashMode &&
        typeof onBulkRestore === 'function' &&
        (selectedFiles.length > 0 || selectedFolders.length > 0);
    const canBulkFileActions =
        !isTrashMode && selectedFolders.length === 0 && selectedFiles.length > 0;
    const canBulkDownload =
        canBulkFileActions &&
        typeof onBulkDownload === 'function' &&
        selectionAllowsDownload;
    const canBulkShare =
        canBulkFileActions &&
        typeof onBulkShare === 'function' &&
        selectionAllowsEdit;
    const canBulkMove =
        canBulkFileActions &&
        typeof onBulkMove === 'function' &&
        selectionAllowsEdit;
    const dragSelectionRef = useRef<{
        active: boolean;
        anchorIndex: number;
        additive: boolean;
        baseline: Set<string>;
    }>({
        active: false,
        anchorIndex: -1,
        additive: false,
        baseline: new Set<string>(),
    });
    const handleSelectionAction = useCallback(async () => {
        const actionHandler = isTrashMode ? onBulkPurge : onBulkTrash;
        if (
            !actionHandler ||
            isSelectionActionProcessing ||
            (!canBulkTrash && !canBulkPurge)
        ) {
            return;
        }

        setSelectionActionError(null);
        setIsSelectionActionProcessing(true);

        try {
            await actionHandler({
                files: selectedFiles,
                folders: selectedFolders,
            });
            dragSelectionRef.current.active = false;
            setSelectedKeys([]);
        } catch (error) {
            setSelectionActionError(
                error instanceof Error
                    ? error.message
                    : isTrashMode
                      ? 'Unable to delete selected items forever.'
                      : 'Unable to move selected items to trash.',
            );
        } finally {
            setIsSelectionActionProcessing(false);
        }
    }, [
        canBulkPurge,
        canBulkTrash,
        isSelectionActionProcessing,
        isTrashMode,
        onBulkPurge,
        onBulkTrash,
        selectedFiles,
        selectedFolders,
    ]);
    const handleBulkRestore = useCallback(async () => {
        if (!onBulkRestore || isSelectionActionProcessing || !canBulkRestore) {
            return;
        }

        setSelectionActionError(null);
        setIsSelectionActionProcessing(true);

        try {
            await onBulkRestore({
                files: selectedFiles,
                folders: selectedFolders,
            });
            dragSelectionRef.current.active = false;
            setSelectedKeys([]);
        } catch (error) {
            setSelectionActionError(
                error instanceof Error
                    ? error.message
                    : 'Unable to restore selected items.',
            );
        } finally {
            setIsSelectionActionProcessing(false);
        }
    }, [
        canBulkRestore,
        isSelectionActionProcessing,
        onBulkRestore,
        selectedFiles,
        selectedFolders,
    ]);
    const handleBulkDownload = useCallback(async () => {
        if (!onBulkDownload || isSelectionActionProcessing || !canBulkDownload) {
            return;
        }

        setSelectionActionError(null);
        setIsSelectionActionProcessing(true);

        try {
            await onBulkDownload({
                files: selectedFiles,
                folders: selectedFolders,
            });
        } catch (error) {
            setSelectionActionError(
                error instanceof Error
                    ? error.message
                    : 'Unable to download selected files.',
            );
        } finally {
            setIsSelectionActionProcessing(false);
        }
    }, [
        canBulkDownload,
        isSelectionActionProcessing,
        onBulkDownload,
        selectedFiles,
        selectedFolders,
    ]);
    const handleBulkShare = useCallback(() => {
        if (!onBulkShare || isSelectionActionProcessing || !canBulkShare) {
            return;
        }

        setSelectionActionError(null);
        onBulkShare({
            files: selectedFiles,
            folders: selectedFolders,
        });
    }, [
        canBulkShare,
        isSelectionActionProcessing,
        onBulkShare,
        selectedFiles,
        selectedFolders,
    ]);
    const handleBulkMove = useCallback(() => {
        if (!onBulkMove || isSelectionActionProcessing || !canBulkMove) {
            return;
        }

        setSelectionActionError(null);
        onBulkMove({
            files: selectedFiles,
            folders: selectedFolders,
        });
    }, [
        canBulkMove,
        isSelectionActionProcessing,
        onBulkMove,
        selectedFiles,
        selectedFolders,
    ]);

    useEffect(() => {
        const stopDragSelection = () => {
            dragSelectionRef.current.active = false;
        };

        window.addEventListener('mouseup', stopDragSelection);
        window.addEventListener('blur', stopDragSelection);

        return () => {
            window.removeEventListener('mouseup', stopDragSelection);
            window.removeEventListener('blur', stopDragSelection);
        };
    }, []);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (isInteractiveTarget(event.target)) {
                return;
            }

            if (
                (event.ctrlKey || event.metaKey) &&
                event.key.toLowerCase() === 'a'
            ) {
                if (!rowKeys.length) {
                    return;
                }

                event.preventDefault();
                dragSelectionRef.current.active = false;
                setSelectionActionError(null);
                setSelectedKeys(rowKeys);

                return;
            }

            if (event.key === 'Delete') {
                if (
                    event.repeat ||
                    !effectiveSelectedKeys.length ||
                    (!canBulkTrash && !canBulkPurge)
                ) {
                    return;
                }

                event.preventDefault();
                void handleSelectionAction();

                return;
            }

            if (event.key !== 'Escape' || !effectiveSelectedKeys.length) {
                return;
            }

            event.preventDefault();
            dragSelectionRef.current.active = false;
            setSelectedKeys([]);
            setSelectionActionError(null);
        };

        window.addEventListener('keydown', onKeyDown);

        return () => {
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [
        canBulkPurge,
        canBulkTrash,
        effectiveSelectedKeys.length,
        handleSelectionAction,
        rowKeys,
    ]);

    const getRangeKeys = (fromIndex: number, toIndex: number): string[] => {
        const start = Math.min(fromIndex, toIndex);
        const end = Math.max(fromIndex, toIndex);

        return rows.slice(start, end + 1).map((row) => row.key);
    };

    const applyDragRange = (targetIndex: number) => {
        const state = dragSelectionRef.current;

        if (!state.active || state.anchorIndex < 0) {
            return;
        }

        const nextSelection = state.additive
            ? new Set(state.baseline)
            : new Set<string>();

        getRangeKeys(state.anchorIndex, targetIndex).forEach((itemKey) =>
            nextSelection.add(itemKey),
        );

        setSelectedKeys(Array.from(nextSelection));
    };

    const handleRowMouseDown = (
        event: MouseEvent<HTMLTableRowElement>,
        row: UnifiedRow,
        rowIndex: number,
    ) => {
        if (event.button !== 0 || isInteractiveTarget(event.target)) {
            return;
        }

        event.preventDefault();
        const additive = event.ctrlKey || event.metaKey;
        const nextSelection = new Set(effectiveSelectedKeys);

        if (additive) {
            if (nextSelection.has(row.key)) {
                nextSelection.delete(row.key);
            } else {
                nextSelection.add(row.key);
            }
        } else {
            nextSelection.clear();
            nextSelection.add(row.key);
        }

        setSelectedKeys(Array.from(nextSelection));
        dragSelectionRef.current = {
            active: true,
            anchorIndex: rowIndex,
            additive,
            baseline: additive ? new Set(nextSelection) : new Set<string>(),
        };
    };

    const handleRowMouseEnter = (
        event: MouseEvent<HTMLTableRowElement>,
        rowIndex: number,
    ) => {
        if (!dragSelectionRef.current.active) {
            return;
        }

        if ((event.buttons & 1) !== 1) {
            dragSelectionRef.current.active = false;

            return;
        }

        applyDragRange(rowIndex);
    };

    const handleRowDoubleClick = (
        event: MouseEvent<HTMLTableRowElement>,
        row: UnifiedRow,
    ) => {
        if (isInteractiveTarget(event.target)) {
            return;
        }

        if (row.kind === 'folder') {
            if (isTrashMode) {
                onOpenFolder?.(row.value);

                return;
            }

            router.visit(`/folders/${row.value.public_id}`);

            return;
        }

        if (isTrashMode) {
            return;
        }

        window.location.assign(`/files/${row.value.public_id}/download`);
    };

    if (loading) {
        return <TableCardSkeleton columns={isTrashMode ? 6 : 5} rows={8} />;
    }

    if (!rows.length) {
        return (
            <div className="rounded-lg border bg-card p-6 text-sm text-muted-foreground">
                {emptyMessage}
            </div>
        );
    }

    return (
        <div className="relative overflow-hidden rounded-xl border border-border bg-card">
            {effectiveSelectedKeys.length ? (
                <div className="pointer-events-none absolute top-2 right-3 left-3 z-20">
                    <div className="pointer-events-auto inline-flex flex-wrap items-center gap-2 rounded-md border border-border/80 bg-background/95 px-2 py-1 text-xs text-muted-foreground shadow-soft-sm backdrop-blur">
                        <div className="inline-flex items-center gap-2">
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="size-6"
                                onClick={() => {
                                    dragSelectionRef.current.active = false;
                                    setSelectedKeys([]);
                                    setSelectionActionError(null);
                                }}
                                aria-label="Clear selection"
                            >
                                <X className="size-3.5" />
                            </Button>
                            <span>{effectiveSelectedKeys.length} selected</span>
                        </div>
                        {canBulkRestore ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="secondary"
                                disabled={isSelectionActionProcessing}
                                onClick={() => {
                                    void handleBulkRestore();
                                }}
                            >
                                <RotateCcw className="size-4" />
                                Restore selected
                            </Button>
                        ) : null}
                        {canBulkTrash || canBulkPurge ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="destructive"
                                disabled={isSelectionActionProcessing}
                                onClick={() => {
                                    void handleSelectionAction();
                                }}
                            >
                                <Trash2 className="size-4" />
                                {isTrashMode
                                    ? 'Delete forever'
                                    : 'Move to trash'}
                            </Button>
                        ) : null}
                        {canBulkDownload ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="secondary"
                                disabled={isSelectionActionProcessing}
                                onClick={() => {
                                    void handleBulkDownload();
                                }}
                            >
                                <Download className="size-4" />
                                Download
                            </Button>
                        ) : null}
                        {canBulkShare ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="secondary"
                                disabled={isSelectionActionProcessing}
                                onClick={() => {
                                    handleBulkShare();
                                }}
                            >
                                <Share2 className="size-4" />
                                Share
                            </Button>
                        ) : null}
                        {canBulkMove ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="secondary"
                                disabled={isSelectionActionProcessing}
                                onClick={() => {
                                    handleBulkMove();
                                }}
                            >
                                <FolderInput className="size-4" />
                                Move
                            </Button>
                        ) : null}
                    </div>
                    {selectionActionError ? (
                        <p className="pointer-events-auto mt-1 rounded-md bg-background/95 px-2 py-1 text-xs text-warning shadow-soft-sm">
                            {selectionActionError}
                        </p>
                    ) : null}
                </div>
            ) : null}
            <div className="overflow-x-auto">
                <table
                    className={cn(
                        'w-full table-fixed text-sm',
                        isTrashMode ? 'min-w-[1120px]' : 'min-w-[920px]',
                    )}
                >
                    <thead className="border-b border-border/80 bg-muted/25">
                        <tr className="text-left text-sm font-semibold text-foreground">
                            <th
                                className={cn(
                                    'px-5 py-4',
                                    isTrashMode ? 'w-[36%]' : 'w-[44%]',
                                )}
                            >
                                <span className="inline-flex items-center gap-2">
                                    Name
                                    <span className="inline-flex size-6 items-center justify-center rounded-full bg-primary/15 text-primary">
                                        <ArrowUp className="size-3.5" />
                                    </span>
                                </span>
                            </th>
                            <th
                                className={cn(
                                    'px-4 py-4',
                                    isTrashMode ? 'w-[14%]' : 'w-[16%]',
                                )}
                            >
                                Owner
                            </th>
                            <th
                                className={cn(
                                    'px-4 py-4',
                                    isTrashMode ? 'w-[14%]' : 'w-[18%]',
                                )}
                            >
                                {isTrashMode ? 'Deleted at' : 'Date modified'}
                            </th>
                            <th
                                className={cn(
                                    'px-4 py-4',
                                    isTrashMode ? 'w-[10%]' : 'w-[12%]',
                                )}
                            >
                                File size
                            </th>
                            {isTrashMode ? (
                                <th className="w-[16%] px-4 py-4">
                                    Original location
                                </th>
                            ) : null}
                            <th
                                className={cn(
                                    'px-5 py-4 text-right',
                                    isTrashMode ? 'w-[10%]' : 'w-[10%]',
                                )}
                            >
                                <span className="inline-flex items-center gap-1">
                                    <ArrowUpDown className="size-4 text-muted-foreground" />
                                    Sort
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border/70">
                        {rows.map((row, rowIndex) =>
                            row.kind === 'folder' ? (
                                <tr
                                    key={row.key}
                                    className={cn(
                                        'transition-colors select-none',
                                        selectedKeySet.has(row.key)
                                            ? 'bg-primary/12 hover:bg-primary/15'
                                            : 'bg-muted/30 hover:bg-muted/45',
                                    )}
                                    onMouseDown={(event) =>
                                        handleRowMouseDown(event, row, rowIndex)
                                    }
                                    onMouseEnter={(event) =>
                                        handleRowMouseEnter(event, rowIndex)
                                    }
                                    onDoubleClick={(event) =>
                                        handleRowDoubleClick(event, row)
                                    }
                                >
                                    <td className="px-5 py-3.5">
                                        <div className="flex min-w-0 items-center gap-3 text-foreground">
                                            <span className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground">
                                                <Folder className="size-5" />
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {row.value.name}
                                                </p>
                                                {!isTrashMode &&
                                                showSharingMarker &&
                                                ownerMatchesCurrentUser(
                                                    row.value.owner?.email,
                                                    currentUser,
                                                ) ? (
                                                    <SharingMarker
                                                        sharing={
                                                            row.value.sharing
                                                        }
                                                    />
                                                ) : null}
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3.5 text-foreground">
                                        <OwnerCell
                                            ownerEmail={
                                                row.value.owner?.email ?? null
                                            }
                                            currentUser={currentUser}
                                            fallbackToCurrentUser={!isTrashMode}
                                        />
                                    </td>
                                    <td className="px-4 py-3.5 text-foreground">
                                        {formatDate(
                                            isTrashMode
                                                ? row.value.deleted_at
                                                : (row.value.updated_at ??
                                                      row.value.created_at),
                                        )}
                                    </td>
                                    <td className="px-4 py-3.5 text-muted-foreground">
                                        {isTrashMode
                                            ? formatTrashedFileCount(
                                                  row.value.trashed_files_count,
                                              )
                                            : '-'}
                                    </td>
                                    {isTrashMode ? (
                                        <td className="px-4 py-3.5 text-foreground">
                                            <span className="block truncate">
                                                {folderOriginalLocation(
                                                    row.value,
                                                )}
                                            </span>
                                        </td>
                                    ) : null}
                                    <td className="px-5 py-3.5 text-right">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    type="button"
                                                    data-no-row-select="true"
                                                >
                                                    <MoreVertical className="size-4" />
                                                    <span className="sr-only">
                                                        Folder actions
                                                    </span>
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {isTrashMode ? (
                                                    <>
                                                        <DropdownMenuItem
                                                            onSelect={(
                                                                event,
                                                            ) => {
                                                                event.preventDefault();
                                                                onRestoreFolder?.(
                                                                    row.value,
                                                                );
                                                            }}
                                                        >
                                                            <RotateCcw className="mr-2" />
                                                            Restore
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            disabled={
                                                                !onPurgeFolder
                                                            }
                                                            onSelect={(
                                                                event,
                                                            ) => {
                                                                event.preventDefault();
                                                                onPurgeFolder?.(
                                                                    row.value,
                                                                );
                                                            }}
                                                        >
                                                            <Trash2 className="mr-2" />
                                                            Delete forever
                                                        </DropdownMenuItem>
                                                    </>
                                                ) : (
                                                    <>
                                                        {onMoveFolder &&
                                                        row.value.access
                                                            ?.can_edit !==
                                                            false ? (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onMoveFolder(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <FolderInput className="mr-2" />
                                                                Move to
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        {onPreviewFolder ? (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onPreviewFolder(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <Eye className="mr-2" />
                                                                Preview
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        {onRenameFolder &&
                                                        row.value.access
                                                            ?.can_edit !==
                                                            false ? (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onRenameFolder(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <Pencil className="mr-2" />
                                                                Rename
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        {onDetailsFolder ? (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onDetailsFolder(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <Info className="mr-2" />
                                                                Details
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        {onRemoveFolderAccess ? (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onRemoveFolderAccess(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <X className="mr-2" />
                                                                Remove from my
                                                                files
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        <DropdownMenuItem
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/folders/${row.value.public_id}/download`}
                                                                className="block w-full cursor-pointer"
                                                            >
                                                                <Download className="mr-2" />
                                                                Download
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        {onShareFolder &&
                                                        row.value.access
                                                            ?.can_edit !==
                                                            false ? (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onShareFolder(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <Share2 className="mr-2" />
                                                                Share
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        {onDeleteFolder &&
                                                        row.value.access
                                                            ?.can_delete !==
                                                            false ? (
                                                            <DropdownMenuItem
                                                                variant="destructive"
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onDeleteFolder(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <Trash2 className="mr-2" />
                                                                Move to trash
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                    </>
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </td>
                                </tr>
                            ) : (
                                <tr
                                    key={row.key}
                                    className={cn(
                                        'transition-colors select-none',
                                        selectedKeySet.has(row.key)
                                            ? 'bg-primary/12 hover:bg-primary/15'
                                            : 'hover:bg-muted/20',
                                    )}
                                    onMouseDown={(event) =>
                                        handleRowMouseDown(event, row, rowIndex)
                                    }
                                    onMouseEnter={(event) =>
                                        handleRowMouseEnter(event, rowIndex)
                                    }
                                    onDoubleClick={(event) =>
                                        handleRowDoubleClick(event, row)
                                    }
                                >
                                    <td className="px-5 py-3.5">
                                        <div className="flex min-w-0 items-center gap-3">
                                            <FileIconCell
                                                file={row.value}
                                                enablePreview={!isTrashMode}
                                            />
                                            <div className="min-w-0">
                                                <p className="truncate text-[15px] font-medium text-foreground">
                                                    {row.value.original_name}
                                                </p>
                                                {!isTrashMode &&
                                                showSharingMarker &&
                                                ownerMatchesCurrentUser(
                                                    row.value.owner?.email,
                                                    currentUser,
                                                ) ? (
                                                    <SharingMarker
                                                        sharing={
                                                            row.value.sharing
                                                        }
                                                    />
                                                ) : null}
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3.5 text-foreground">
                                        <OwnerCell
                                            ownerEmail={
                                                row.value.owner?.email ?? null
                                            }
                                            currentUser={currentUser}
                                        />
                                    </td>
                                    <td className="px-4 py-3.5 text-foreground">
                                        {formatDate(
                                            isTrashMode
                                                ? row.value.deleted_at
                                                : (row.value.updated_at ??
                                                      row.value.created_at),
                                        )}
                                    </td>
                                    <td className="px-4 py-3.5 text-foreground">
                                        {formatBytes(row.value.size_bytes)}
                                    </td>
                                    {isTrashMode ? (
                                        <td className="px-4 py-3.5 text-foreground">
                                            <span className="block truncate">
                                                {fileOriginalLocation(
                                                    row.value,
                                                )}
                                            </span>
                                        </td>
                                    ) : null}
                                    <td className="px-5 py-3.5 text-right">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    type="button"
                                                    data-no-row-select="true"
                                                >
                                                    <MoreVertical className="size-4" />
                                                    <span className="sr-only">
                                                        File actions
                                                    </span>
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {isTrashMode ? (
                                                    <>
                                                        <DropdownMenuItem
                                                            onSelect={(
                                                                event,
                                                            ) => {
                                                                event.preventDefault();
                                                                onRestoreFile?.(
                                                                    row.value,
                                                                );
                                                            }}
                                                        >
                                                            <RotateCcw className="mr-2" />
                                                            Restore
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            disabled={
                                                                !onPurgeFile
                                                            }
                                                            onSelect={(
                                                                event,
                                                            ) => {
                                                                event.preventDefault();
                                                                onPurgeFile?.(
                                                                    row.value,
                                                                );
                                                            }}
                                                        >
                                                            <Trash2 className="mr-2" />
                                                            Delete forever
                                                        </DropdownMenuItem>
                                                    </>
                                                ) : (
                                                    <>
                                                        {onPreviewFile ? (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onPreviewFile(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <Eye className="mr-2" />
                                                                Preview
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        {onMoveFile &&
                                                        row.value.access
                                                            ?.can_edit !==
                                                            false ? (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onMoveFile(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <FolderInput className="mr-2" />
                                                                Move to
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        {row.value.access
                                                            ?.can_download ===
                                                        false ? (
                                                            <DropdownMenuItem
                                                                disabled
                                                            >
                                                                <Download className="mr-2" />
                                                                Download
                                                            </DropdownMenuItem>
                                                        ) : (
                                                            <DropdownMenuItem
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={`/files/${row.value.public_id}/download`}
                                                                    className="block w-full cursor-pointer"
                                                                >
                                                                    <Download className="mr-2" />
                                                                    Download
                                                                </Link>
                                                            </DropdownMenuItem>
                                                        )}
                                                        {onShare &&
                                                        row.value.access
                                                            ?.can_edit !==
                                                            false ? (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onShare(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <Share2 className="mr-2" />
                                                                Share
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        {onRename &&
                                                        row.value.access
                                                            ?.can_edit !==
                                                            false ? (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onRename(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <Pencil className="mr-2" />
                                                                Rename
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        {onReplace &&
                                                        row.value.access
                                                            ?.can_edit !==
                                                            false ? (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onReplace(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <Upload className="mr-2" />
                                                                Replace
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        {onDetailsFile ? (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onDetailsFile(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <Info className="mr-2" />
                                                                Details
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        {onRemoveFileAccess ? (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onRemoveFileAccess(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <X className="mr-2" />
                                                                Remove from my
                                                                files
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        {onDelete &&
                                                        row.value.access
                                                            ?.can_delete !==
                                                            false ? (
                                                            <DropdownMenuItem
                                                                variant="destructive"
                                                                onSelect={(
                                                                    event,
                                                                ) => {
                                                                    event.preventDefault();
                                                                    onDelete(
                                                                        row.value,
                                                                    );
                                                                }}
                                                            >
                                                                <Trash2 className="mr-2" />
                                                                Move to trash
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                    </>
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </td>
                                </tr>
                            ),
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
