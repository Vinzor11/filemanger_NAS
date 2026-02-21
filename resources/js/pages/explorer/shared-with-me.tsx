import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    FileItemActionDialogs,
    type DetailsTarget,
    type MoveTarget,
} from '@/components/file-manager/file-item-action-dialogs';
import { FilePreviewModal } from '@/components/file-manager/file-preview-modal';
import {
    FileTable,
    type FileRow,
    type FolderRow,
} from '@/components/file-manager/file-table';
import { LayoutModeToggle } from '@/components/file-manager/layout-mode-toggle';
import { useFileLayoutMode } from '@/hooks/use-file-layout-mode';
import { usePageLoading } from '@/hooks/use-page-loading';
import AppLayout from '@/layouts/app-layout';
import { promptReplaceFile } from '@/lib/file-replace-actions';
import { downloadSelectionFiles } from '@/lib/selection-actions';
import type { BreadcrumbItem } from '@/types';

type PageProps = {
    folders: FolderRow[];
    files: {
        data: FileRow[];
    };
};

type AuthPageProps = PageProps & {
    auth: {
        user: {
            email: string | null;
            name?: string | null;
            avatar?: string | null;
        } | null;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Shared With Me', href: '/shared-with-me' },
];

export default function SharedWithMe({ folders, files }: PageProps) {
    const page = usePage<AuthPageProps>();
    const isPageLoading = usePageLoading();
    const [layoutMode, setLayoutMode] = useFileLayoutMode(
        'explorer-layout-shared-with-me',
    );
    const [moveTarget, setMoveTarget] = useState<MoveTarget | null>(null);
    const [previewFile, setPreviewFile] = useState<FileRow | null>(null);
    const [detailsTarget, setDetailsTarget] = useState<DetailsTarget | null>(
        null,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Shared With Me" />
            <div className="space-y-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold">Shared With Me</h1>
                    <LayoutModeToggle
                        value={layoutMode}
                        onValueChange={setLayoutMode}
                    />
                </div>

                <FileItemActionDialogs
                    folders={folders}
                    moveTarget={moveTarget}
                    onMoveTargetChange={setMoveTarget}
                    detailsTarget={detailsTarget}
                    onDetailsTargetChange={setDetailsTarget}
                />
                <FilePreviewModal
                    file={previewFile}
                    open={previewFile !== null}
                    onOpenChange={(nextOpen) => {
                        if (!nextOpen) {
                            setPreviewFile(null);
                        }
                    }}
                />

                <FileTable
                    folders={folders}
                    files={files.data}
                    currentUser={page.props.auth.user}
                    loading={isPageLoading}
                    layoutMode={layoutMode}
                    onBulkDownload={({
                        files: selectedFiles,
                        folders: selectedFolders,
                    }) => {
                        downloadSelectionFiles({
                            files: selectedFiles,
                            folders: selectedFolders,
                        });
                    }}
                    onBulkMove={({
                        files: selectedFiles,
                        folders: selectedFolders,
                    }) => {
                        if (
                            selectedFiles.length + selectedFolders.length < 1
                        ) {
                            return;
                        }

                        setMoveTarget({
                            kind: 'bulk-selection',
                            files: selectedFiles,
                            folders: selectedFolders,
                        });
                    }}
                    onMoveFile={(file) => setMoveTarget({ kind: 'file', file })}
                    onMoveFolder={(folder) =>
                        setMoveTarget({ kind: 'folder', folder })
                    }
                    onPreviewFile={(file) => setPreviewFile(file)}
                    onPreviewFolder={(folder) =>
                        router.visit(`/folders/${folder.public_id}`)
                    }
                    onDetailsFile={(file) =>
                        setDetailsTarget({ kind: 'file', file })
                    }
                    onDetailsFolder={(folder) =>
                        setDetailsTarget({ kind: 'folder', folder })
                    }
                    onRename={(file) => {
                        const nextName = window
                            .prompt('Rename file', file.original_name)
                            ?.trim();
                        if (!nextName || nextName === file.original_name) {
                            return;
                        }

                        router.patch(
                            `/files/${file.public_id}/rename`,
                            {
                                original_name: nextName,
                            },
                            {
                                preserveScroll: true,
                            },
                        );
                    }}
                    onReplace={(file) => {
                        promptReplaceFile(file.public_id, file.original_name);
                    }}
                    onRenameFolder={(folder) => {
                        const nextName = window
                            .prompt('Rename folder', folder.name)
                            ?.trim();
                        if (!nextName || nextName === folder.name) {
                            return;
                        }

                        router.patch(
                            `/folders/${folder.public_id}`,
                            {
                                name: nextName,
                            },
                            {
                                preserveScroll: true,
                            },
                        );
                    }}
                    onRemoveFileAccess={(file) => {
                        const confirmed = window.confirm(
                            `Remove "${file.original_name}" from your shared files?`,
                        );
                        if (!confirmed) {
                            return;
                        }

                        router.delete(`/files/${file.public_id}/share/me`, {
                            preserveScroll: true,
                            headers: {
                                'X-Idempotency-Key': crypto.randomUUID(),
                            },
                        });
                    }}
                    onRemoveFolderAccess={(folder) => {
                        const confirmed = window.confirm(
                            `Remove folder "${folder.name}" from your shared files?`,
                        );
                        if (!confirmed) {
                            return;
                        }

                        router.delete(`/folders/${folder.public_id}/share/me`, {
                            preserveScroll: true,
                            headers: {
                                'X-Idempotency-Key': crypto.randomUUID(),
                            },
                        });
                    }}
                />
            </div>
        </AppLayout>
    );
}
