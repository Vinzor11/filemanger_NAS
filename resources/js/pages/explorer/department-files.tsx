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
import { NewActionsMenu } from '@/components/file-manager/new-actions-menu';
import { ShareModal } from '@/components/file-manager/share-modal';
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
    { title: 'Department Files', href: '/department-files' },
];

export default function DepartmentFiles({ folders, files }: PageProps) {
    const page = usePage<AuthPageProps>();
    const isPageLoading = usePageLoading();
    const [isUploadProcessing, setIsUploadProcessing] = useState(false);
    const [selectedForShare, setSelectedForShare] = useState<
        | { kind: 'file'; file: FileRow }
        | { kind: 'folder'; folder: FolderRow }
        | { kind: 'bulk-files'; files: FileRow[] }
        | null
    >(null);
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
            <Head title="Department Files" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold">Department Files</h1>
                    <NewActionsMenu
                        uploadFolderOptions={folders.map((folder) => ({
                            public_id: folder.public_id,
                            name: folder.name,
                            path: folder.path,
                        }))}
                        defaultScope="department"
                        showScope={false}
                        canCreateFolder={canCreateDepartmentFolder}
                        onUploadProcessingChange={setIsUploadProcessing}
                    />
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
                        showSharingMarker={false}
                        currentUser={page.props.auth.user}
                        loading={isListLoading}
                        onBulkTrash={moveSelectionToTrash}
                        onBulkDownload={({ files: selectedFiles }) => {
                            downloadSelectionFiles({ files: selectedFiles });
                        }}
                        onBulkShare={({ files: selectedFiles }) => {
                            if (selectedFiles.length < 1) {
                                return;
                            }

                            setSelectedForShare({
                                kind: 'bulk-files',
                                files: selectedFiles,
                            });
                        }}
                        onBulkMove={({ files: selectedFiles }) => {
                            if (selectedFiles.length < 1) {
                                return;
                            }

                            setMoveTarget({
                                kind: 'bulk-files',
                                files: selectedFiles,
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
