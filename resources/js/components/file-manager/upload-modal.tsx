import { router, usePage } from '@inertiajs/react';
import {
    type ChangeEvent,
    type FormEvent,
    type DragEvent,
    type ReactNode,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

type DuplicateMode = 'fail' | 'replace' | 'auto_rename';
type UploadSelectionMode = 'files' | 'folder';
type DuplicateChoice = Exclude<DuplicateMode, 'fail'> | 'cancel';
type DuplicatePrompt = {
    fileName: string;
};

type PageProps = {
    uploadLimits?: {
        maxFileBytes?: number;
    };
};

type UploadFolderOption = {
    public_id: string;
    name: string;
    path?: string | null;
};

type UploadModalProps = {
    folderPublicId?: string | null;
    folderOptions?: UploadFolderOption[];
    onProcessingChange?: (isProcessing: boolean) => void;
    buttonLabel?: string;
    selectionMode?: UploadSelectionMode;
    trigger?: ReactNode;
    hideTrigger?: boolean;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};

function normaliseErrorMessage(error: unknown): string {
    if (Array.isArray(error)) {
        return error.map((entry) => String(entry)).join(' ');
    }

    if (typeof error === 'string') {
        return error;
    }

    if (error && typeof error === 'object') {
        return Object.values(error as Record<string, unknown>)
            .map((entry) => normaliseErrorMessage(entry))
            .join(' ');
    }

    return '';
}

function fileKey(file: File): string {
    return `${file.name}:${file.size}:${file.lastModified}`;
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function UploadModal({
    folderPublicId = null,
    folderOptions = [],
    onProcessingChange,
    buttonLabel = 'Upload file',
    selectionMode = 'files',
    trigger,
    hideTrigger = false,
    open,
    onOpenChange,
}: UploadModalProps) {
    const page = usePage<PageProps>();
    const [internalOpen, setInternalOpen] = useState(false);
    const isControlled = typeof open === 'boolean';
    const dialogOpen = isControlled ? open : internalOpen;
    const setDialogOpen = useMemo(
        () => onOpenChange ?? setInternalOpen,
        [onOpenChange],
    );

    const fileInputRef = useRef<HTMLInputElement | null>(null);
    const folderInputRef = useRef<HTMLInputElement | null>(null);
    const duplicateChoiceResolver = useRef<
        ((choice: DuplicateChoice) => void) | null
    >(null);

    const [selectedFiles, setSelectedFiles] = useState<File[]>([]);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const [isUploading, setIsUploading] = useState(false);
    const [completedUploads, setCompletedUploads] = useState(0);
    const [activeUploadName, setActiveUploadName] = useState<string | null>(
        null,
    );
    const [isDragging, setIsDragging] = useState(false);
    const [duplicatePrompt, setDuplicatePrompt] =
        useState<DuplicatePrompt | null>(null);
    const [selectedDestinationFolderId, setSelectedDestinationFolderId] =
        useState('');
    const maxFileBytes = page.props.uploadLimits?.maxFileBytes ?? 52428800;
    const hasFixedDestination = Boolean(folderPublicId);
    const effectiveDestinationFolderId = hasFixedDestination
        ? folderPublicId
        : selectedDestinationFolderId;

    const uploadTitle =
        selectionMode === 'folder' ? 'Upload folder' : 'Upload files';
    const chooseLabel =
        selectionMode === 'folder' ? 'Choose folder' : 'Choose files';
    const helperText =
        selectionMode === 'folder'
            ? 'Select one folder to upload all files inside it.'
            : 'Drop files here or choose files to upload.';

    const resetState = () => {
        if (duplicateChoiceResolver.current) {
            duplicateChoiceResolver.current('cancel');
            duplicateChoiceResolver.current = null;
        }
        setSelectedFiles([]);
        setUploadError(null);
        setCompletedUploads(0);
        setActiveUploadName(null);
        setIsDragging(false);
        setDuplicatePrompt(null);
    };

    useEffect(() => {
        onProcessingChange?.(isUploading);

        return () => {
            onProcessingChange?.(false);
        };
    }, [isUploading, onProcessingChange]);

    useEffect(() => {
        if (!folderInputRef.current) {
            return;
        }

        folderInputRef.current.setAttribute('webkitdirectory', '');
        folderInputRef.current.setAttribute('directory', '');
    }, []);

    useEffect(() => {
        return () => {
            if (duplicateChoiceResolver.current) {
                duplicateChoiceResolver.current('cancel');
                duplicateChoiceResolver.current = null;
            }
        };
    }, []);

    useEffect(() => {
        if (!dialogOpen) {
            return;
        }

        if (folderPublicId) {
            setSelectedDestinationFolderId(folderPublicId);

            return;
        }

        setSelectedDestinationFolderId('');
    }, [dialogOpen, folderPublicId]);

    const mergeFiles = (incoming: FileList | File[]) => {
        const incomingFiles = Array.from(incoming);
        if (incomingFiles.length === 0) {
            return;
        }

        const oversizedFiles = incomingFiles.filter(
            (file) => file.size > maxFileBytes,
        );
        const nextFiles = incomingFiles.filter(
            (file) => file.size <= maxFileBytes,
        );

        if (oversizedFiles.length > 0) {
            const names = oversizedFiles
                .slice(0, 3)
                .map((file) => file.name)
                .join(', ');
            const moreCount =
                oversizedFiles.length - Math.min(oversizedFiles.length, 3);
            setUploadError(
                `File too large (max ${formatFileSize(maxFileBytes)}): ${names}${moreCount > 0 ? ` and ${moreCount} more` : ''}`,
            );
        }

        setSelectedFiles((previousFiles) => {
            const seen = new Set(previousFiles.map(fileKey));
            const merged = [...previousFiles];
            for (const file of nextFiles) {
                const key = fileKey(file);
                if (seen.has(key)) {
                    continue;
                }
                merged.push(file);
                seen.add(key);
            }
            return merged;
        });
        if (oversizedFiles.length === 0) {
            setUploadError(null);
        }
    };

    const handleDialogOpenChange = (nextOpen: boolean) => {
        if (!nextOpen && isUploading) {
            return;
        }

        setDialogOpen(nextOpen);
        if (!nextOpen) {
            resetState();
        }
    };

    const chooseFiles = () => {
        if (selectionMode === 'folder') {
            folderInputRef.current?.click();
            return;
        }

        fileInputRef.current?.click();
    };

    const handleFileInputChange = (event: ChangeEvent<HTMLInputElement>) => {
        mergeFiles(event.target.files ?? []);
        event.target.value = '';
    };

    const handleDragOver = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setIsDragging(true);
    };

    const handleDragLeave = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setIsDragging(false);
    };

    const handleDrop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setIsDragging(false);
        mergeFiles(event.dataTransfer.files);
    };

    const waitForDuplicateChoice = (
        fileName: string,
    ): Promise<DuplicateChoice> =>
        new Promise((resolve) => {
            duplicateChoiceResolver.current = resolve;
            setDuplicatePrompt({ fileName });
        });

    const resolveDuplicateChoice = (choice: DuplicateChoice) => {
        duplicateChoiceResolver.current?.(choice);
        duplicateChoiceResolver.current = null;
        setDuplicatePrompt(null);
    };

    const uploadSingleFile = (
        file: File,
        destinationFolderId: string,
        duplicateMode: DuplicateMode,
    ): Promise<
        | { success: true }
        | { success: false; duplicate: boolean; message: string }
    > =>
        new Promise((resolve) => {
            router.post(
                '/files',
                {
                    folder_id: destinationFolderId,
                    file,
                    duplicate_mode: duplicateMode,
                    original_name: file.name,
                },
                {
                    forceFormData: true,
                    preserveScroll: true,
                    preserveState: true,
                    headers: {
                        'X-Idempotency-Key': crypto.randomUUID(),
                    },
                    onSuccess: () => {
                        resolve({ success: true });
                    },
                    onError: (errors) => {
                        const message =
                            normaliseErrorMessage(errors.file) ||
                            normaliseErrorMessage(errors) ||
                            'Upload failed.';
                        resolve({
                            success: false,
                            duplicate: message
                                .toLowerCase()
                                .includes('already exists'),
                            message,
                        });
                    },
                },
            );
        });

    const submit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (selectedFiles.length === 0 || isUploading) {
            return;
        }

        if (!effectiveDestinationFolderId) {
            setUploadError('Select a destination folder first.');

            return;
        }

        setUploadError(null);
        setCompletedUploads(0);
        setIsUploading(true);

        let completedAll = false;

        try {
            for (let index = 0; index < selectedFiles.length; index++) {
                const file = selectedFiles[index];
                let duplicateMode: DuplicateMode = 'fail';

                setActiveUploadName(file.name);

                while (true) {
                    const result = await uploadSingleFile(
                        file,
                        effectiveDestinationFolderId,
                        duplicateMode,
                    );

                    if (result.success) {
                        setCompletedUploads(index + 1);
                        break;
                    }

                    if (result.duplicate) {
                        const choice = await waitForDuplicateChoice(file.name);

                        if (choice === 'cancel') {
                            setUploadError(
                                'Upload cancelled. Resolve duplicates to continue.',
                            );
                            return;
                        }

                        duplicateMode = choice;
                        continue;
                    }

                    setUploadError(result.message);
                    return;
                }
            }

            completedAll = true;
        } finally {
            setIsUploading(false);
            setActiveUploadName(null);
            setDuplicatePrompt(null);
            if (duplicateChoiceResolver.current) {
                duplicateChoiceResolver.current('cancel');
                duplicateChoiceResolver.current = null;
            }
            if (completedAll) {
                setDialogOpen(false);
                resetState();
            }
        }
    };

    return (
        <Dialog open={dialogOpen} onOpenChange={handleDialogOpenChange}>
            {hideTrigger ? null : (
                <DialogTrigger asChild>
                    {trigger ?? <Button type="button">{buttonLabel}</Button>}
                </DialogTrigger>
            )}
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{uploadTitle}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <Input
                        ref={fileInputRef}
                        type="file"
                        multiple
                        className="hidden"
                        onChange={handleFileInputChange}
                    />
                    <input
                        ref={folderInputRef}
                        type="file"
                        multiple
                        className="hidden"
                        onChange={handleFileInputChange}
                    />

                    {!hasFixedDestination ? (
                        <div className="space-y-2">
                            <label
                                htmlFor="destination_folder_id"
                                className="text-sm font-medium"
                            >
                                Destination folder
                            </label>
                            <select
                                id="destination_folder_id"
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                                value={selectedDestinationFolderId}
                                onChange={(event) =>
                                    setSelectedDestinationFolderId(
                                        event.target.value,
                                    )
                                }
                                disabled={isUploading}
                                required
                            >
                                <option value="">Select folder</option>
                                {folderOptions.map((folderOption) => (
                                    <option
                                        key={folderOption.public_id}
                                        value={folderOption.public_id}
                                    >
                                        {folderOption.path?.trim()
                                            ? folderOption.path
                                            : folderOption.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    ) : null}

                    <div
                        className={`rounded-md border border-dashed p-6 text-center transition-colors ${
                            isDragging
                                ? 'border-primary bg-primary/5'
                                : 'border-border'
                        }`}
                        onDragOver={handleDragOver}
                        onDragLeave={handleDragLeave}
                        onDrop={handleDrop}
                    >
                        <p className="text-sm text-muted-foreground">
                            {helperText}
                        </p>
                        <div className="mt-3 flex flex-wrap items-center justify-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={chooseFiles}
                                disabled={isUploading}
                            >
                                {chooseLabel}
                            </Button>
                            {selectedFiles.length > 0 ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => {
                                        setSelectedFiles([]);
                                        setUploadError(null);
                                    }}
                                    disabled={isUploading}
                                >
                                    Clear
                                </Button>
                            ) : null}
                        </div>
                    </div>

                    {selectedFiles.length > 0 ? (
                        <div className="rounded-md border border-border/70">
                            <div className="border-b border-border/70 px-3 py-2 text-xs font-medium text-muted-foreground">
                                {selectedFiles.length} file
                                {selectedFiles.length === 1 ? '' : 's'} ready to
                                upload
                            </div>
                            <ul className="max-h-44 divide-y divide-border/70 overflow-y-auto">
                                {selectedFiles.map((file) => (
                                    <li
                                        key={fileKey(file)}
                                        className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                                    >
                                        <span className="truncate">
                                            {file.webkitRelativePath ||
                                                file.name}
                                        </span>
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {formatFileSize(file.size)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : null}

                    {duplicatePrompt ? (
                        <div className="rounded-md border border-warning/40 bg-warning/5 p-3">
                            <p className="text-sm">
                                <span className="font-medium">
                                    Duplicate detected:
                                </span>{' '}
                                <span className="font-medium">
                                    {duplicatePrompt.fileName}
                                </span>{' '}
                                already exists in this folder.
                            </p>
                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        resolveDuplicateChoice('auto_rename')
                                    }
                                >
                                    Rename upload
                                </Button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={() =>
                                        resolveDuplicateChoice('replace')
                                    }
                                >
                                    Replace existing
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() =>
                                        resolveDuplicateChoice('cancel')
                                    }
                                >
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    ) : null}

                    {uploadError ? (
                        <p className="text-sm text-warning">{uploadError}</p>
                    ) : null}

                    {isUploading ? (
                        <p className="text-sm text-muted-foreground">
                            Uploading{' '}
                            {Math.min(
                                completedUploads + 1,
                                selectedFiles.length || 1,
                            )}{' '}
                            of {Math.max(selectedFiles.length, 1)}
                            {activeUploadName ? `: ${activeUploadName}` : ''}
                        </p>
                    ) : null}

                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            disabled={
                                isUploading ||
                                selectedFiles.length === 0 ||
                                !effectiveDestinationFolderId ||
                                duplicatePrompt !== null
                            }
                        >
                            {isUploading && <Spinner />}
                            Upload
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
