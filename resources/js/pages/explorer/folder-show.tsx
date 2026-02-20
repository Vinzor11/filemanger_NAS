import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
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

type FolderData = {
    public_id: string;
    name: string;
    visibility: 'private' | 'department' | 'shared';
    parent: { public_id: string; name: string } | null;
};

type PageProps = {
    folder: FolderData;
    abilities?: {
        can_upload?: boolean;
        can_create_folder?: boolean;
    };
    breadcrumbTrail: Array<{ public_id: string; name: string }>;
    children: FolderRow[];
    files: { data: FileRow[] };
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

export default function FolderShow({
    folder,
    abilities,
    breadcrumbTrail,
    children,
    files,
}: PageProps) {
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
    const isListLoading = isPageLoading || isUploadProcessing;
    const homeLabel =
        folder.visibility === 'department' ? 'Department Files' : 'My Files';
    const homeHref =
        folder.visibility === 'department' ? '/department-files' : '/my-files';
    const canUploadToFolder = abilities?.can_upload ?? true;
    const canCreateFolderInFolder = abilities?.can_create_folder ?? true;
    const canShowNewActions = canUploadToFolder || canCreateFolderInFolder;

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            { title: homeLabel, href: homeHref },
            ...breadcrumbTrail.map((item) => ({
                title: item.name,
                href: `/folders/${item.public_id}`,
            })),
        ],
        [breadcrumbTrail, homeHref, homeLabel],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={folder.name} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="space-y-2">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h1 className="text-xl font-semibold">{folder.name}</h1>
                        {canShowNewActions ? (
                            <NewActionsMenu
                                folderPublicId={folder.public_id}
                                parentFolderId={folder.public_id}
                                defaultScope={
                                    folder.visibility === 'department'
                                        ? 'department'
                                        : 'private'
                                }
                                showScope={false}
                                canCreateFolder={canCreateFolderInFolder}
                                canUpload={canUploadToFolder}
                                onUploadProcessingChange={setIsUploadProcessing}
                            />
                        ) : null}
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
                        folders={children}
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
                        folders={children}
                        files={files.data}
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
                        onDeleteFolder={(childFolder) =>
                            router.delete(`/folders/${childFolder.public_id}`, {
                                headers: {
                                    'X-Idempotency-Key': crypto.randomUUID(),
                                },
                            })
                        }
                        onShare={(file) =>
                            setSelectedForShare({ kind: 'file', file })
                        }
                        onShareFolder={(childFolder) =>
                            setSelectedForShare({
                                kind: 'folder',
                                folder: childFolder,
                            })
                        }
                        onMoveFile={(file) =>
                            setMoveTarget({ kind: 'file', file })
                        }
                        onMoveFolder={(childFolder) =>
                            setMoveTarget({
                                kind: 'folder',
                                folder: childFolder,
                            })
                        }
                        onPreviewFile={(file) => setPreviewFile(file)}
                        onPreviewFolder={(childFolder) =>
                            router.visit(`/folders/${childFolder.public_id}`)
                        }
                        onDetailsFile={(file) =>
                            setDetailsTarget({ kind: 'file', file })
                        }
                        onDetailsFolder={(childFolder) =>
                            setDetailsTarget({
                                kind: 'folder',
                                folder: childFolder,
                            })
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
                        onRenameFolder={(childFolder) => {
                            const nextName = window
                                .prompt('Rename folder', childFolder.name)
                                ?.trim();
                            if (!nextName || nextName === childFolder.name) {
                                return;
                            }

                            router.patch(
                                `/folders/${childFolder.public_id}`,
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
