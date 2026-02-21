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
import { NewActionsMenu } from '@/components/file-manager/new-actions-menu';
import {
    ShareModal,
    type ShareTarget,
} from '@/components/file-manager/share-modal';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useFileLayoutMode } from '@/hooks/use-file-layout-mode';
import { usePageLoading } from '@/hooks/use-page-loading';
import AppLayout from '@/layouts/app-layout';
import { promptReplaceFile } from '@/lib/file-replace-actions';
import { downloadSelectionFiles } from '@/lib/selection-actions';
import { moveSelectionToTrash } from '@/lib/trash-actions';
import type { BreadcrumbItem } from '@/types';

type PageProps = {
    folders: FolderRow[];
    files: {
        data: FileRow[];
    };
    filters: Record<string, unknown>;
    flash?: {
        share_link_url?: string;
    };
};

type AuthPageProps = PageProps & {
    auth: {
        user: {
            permissions: string[];
            email: string | null;
            name?: string | null;
            avatar?: string | null;
        } | null;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'My Files', href: '/my-files' },
];

export default function MyFiles({ folders, files }: PageProps) {
    const page = usePage<AuthPageProps>();
    const isPageLoading = usePageLoading();
    const [layoutMode, setLayoutMode] = useFileLayoutMode(
        'explorer-layout-my-files',
    );
    const [isUploadProcessing, setIsUploadProcessing] = useState(false);
    const [selectedForShare, setSelectedForShare] = useState<ShareTarget | null>(
        null,
    );
    const [moveTarget, setMoveTarget] = useState<MoveTarget | null>(null);
    const [previewFile, setPreviewFile] = useState<FileRow | null>(null);
    const [detailsTarget, setDetailsTarget] = useState<DetailsTarget | null>(
        null,
    );

    const canCreateDepartmentFolder =
        page.props.auth.user?.permissions.includes(
            'folders.create_department',
        ) ?? false;
    const isListLoading = isPageLoading || isUploadProcessing;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Files" />

            <div className="space-y-6 p-4 md:p-6">
                {page.props.flash?.share_link_url ? (
                    <Alert>
                        <AlertDescription>
                            Share link created:{' '}
                            <a
                                href={page.props.flash.share_link_url}
                                className="underline"
                            >
                                {page.props.flash.share_link_url}
                            </a>
                        </AlertDescription>
                    </Alert>
                ) : null}

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold">My Files</h1>
                    <div className="flex items-center gap-2">
                        <LayoutModeToggle
                            value={layoutMode}
                            onValueChange={setLayoutMode}
                        />
                        <NewActionsMenu
                            uploadFolderOptions={folders.map((folder) => ({
                                public_id: folder.public_id,
                                name: folder.name,
                                path: folder.path,
                            }))}
                            defaultScope="private"
                            showScope={canCreateDepartmentFolder}
                            onUploadProcessingChange={setIsUploadProcessing}
                        />
                    </div>
                </div>

                <div className="space-y-3">
                    {selectedForShare ? (
                        <ShareModal
                            key={
                                selectedForShare.kind === 'file'
                                    ? `file:${selectedForShare.file.public_id}`
                                    : selectedForShare.kind === 'folder'
                                      ? `folder:${selectedForShare.folder.public_id}`
                                      : `bulk:${selectedForShare.files
                                            .map((file) => file.public_id)
                                            .join('|')}:${selectedForShare.folders
                                            .map((folder) => folder.public_id)
                                            .join('|')}`
                            }
                            target={selectedForShare}
                            onOpenChange={(nextOpen) => {
                                if (!nextOpen) {
                                    setSelectedForShare(null);
                                }
                            }}
                        />
                    ) : null}

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
                        loading={isListLoading}
                        layoutMode={layoutMode}
                        onBulkTrash={moveSelectionToTrash}
                        onBulkDownload={({
                            files: selectedFiles,
                            folders: selectedFolders,
                        }) => {
                            downloadSelectionFiles({
                                files: selectedFiles,
                                folders: selectedFolders,
                            });
                        }}
                        onBulkShare={({
                            files: selectedFiles,
                            folders: selectedFolders,
                        }) => {
                            if (
                                selectedFiles.length + selectedFolders.length <
                                1
                            ) {
                                return;
                            }

                            setSelectedForShare({
                                kind: 'bulk-selection',
                                files: selectedFiles,
                                folders: selectedFolders,
                            });
                        }}
                        onBulkMove={({
                            files: selectedFiles,
                            folders: selectedFolders,
                        }) => {
                            if (
                                selectedFiles.length + selectedFolders.length <
                                1
                            ) {
                                return;
                            }

                            setMoveTarget({
                                kind: 'bulk-selection',
                                files: selectedFiles,
                                folders: selectedFolders,
                            });
                        }}
                        onDeleteFolder={(folder) =>
                            router.delete(`/folders/${folder.public_id}`, {
                                headers: {
                                    'X-Idempotency-Key': crypto.randomUUID(),
                                },
                            })
                        }
                        onShare={(file) =>
                            setSelectedForShare({ kind: 'file', file })
                        }
                        onShareFolder={(folder) =>
                            setSelectedForShare({ kind: 'folder', folder })
                        }
                        onMoveFile={(file) =>
                            setMoveTarget({ kind: 'file', file })
                        }
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
                            promptReplaceFile(
                                file.public_id,
                                file.original_name,
                            );
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
                        onDelete={(file) =>
                            router.delete(`/files/${file.public_id}`, {
                                headers: {
                                    'X-Idempotency-Key': crypto.randomUUID(),
                                },
                            })
                        }
                    />
                </div>
            </div>
        </AppLayout>
    );
}
