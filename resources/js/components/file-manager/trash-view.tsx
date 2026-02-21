import { router, usePage } from '@inertiajs/react';
import {
    FileTable,
    type FileRow,
    type FolderRow,
} from '@/components/file-manager/file-table';
import { type FileLayoutMode } from '@/hooks/use-file-layout-mode';
import { deleteSelectionForever } from '@/lib/purge-actions';
import { restoreSelection } from '@/lib/restore-actions';

type AuthPageProps = {
    auth: {
        user: {
            email: string | null;
            name?: string | null;
            avatar?: string | null;
        } | null;
    };
};

type TrashViewProps = {
    folders: FolderRow[];
    files: FileRow[];
    loading?: boolean;
    layoutMode?: FileLayoutMode;
};

export function TrashView({
    folders,
    files,
    loading = false,
    layoutMode = 'table',
}: TrashViewProps) {
    const page = usePage<AuthPageProps>();

    return (
        <FileTable
            folders={folders}
            files={files}
            currentUser={page.props.auth.user}
            loading={loading}
            viewMode="trash"
            layoutMode={layoutMode}
            emptyMessage="Trash is empty."
            onOpenFolder={(folder) =>
                router.visit(`/trash/folders/${folder.public_id}`)
            }
            onBulkRestore={restoreSelection}
            onBulkPurge={deleteSelectionForever}
            onPurgeFolder={(folder) =>
                router.delete(`/folders/${folder.public_id}/purge`, {
                    data: { silent: true },
                    headers: { 'X-Idempotency-Key': crypto.randomUUID() },
                })
            }
            onPurgeFile={(file) =>
                router.delete(`/files/${file.public_id}/purge`, {
                    data: { silent: true },
                    headers: { 'X-Idempotency-Key': crypto.randomUUID() },
                })
            }
            onRestoreFolder={(folder) =>
                router.post(
                    `/folders/${folder.public_id}/restore`,
                    {},
                    {
                        headers: { 'X-Idempotency-Key': crypto.randomUUID() },
                    },
                )
            }
            onRestoreFile={(file) =>
                router.post(
                    `/files/${file.public_id}/restore`,
                    {},
                    {
                        headers: { 'X-Idempotency-Key': crypto.randomUUID() },
                    },
                )
            }
        />
    );
}
