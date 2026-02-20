import { router } from '@inertiajs/react';
import { emitToast } from '@/lib/toast';

type TrashItem = {
    public_id: string;
};

function requestBulkTrash(files: string[], folders: string[]): Promise<void> {
    return new Promise((resolve, reject) => {
        router.post(
            '/selection/trash',
            {
                files,
                folders,
                silent: true,
            },
            {
                preserveScroll: true,
                preserveState: true,
                headers: {
                    'X-Idempotency-Key': crypto.randomUUID(),
                },
                onSuccess: () => resolve(),
                onError: () =>
                    reject(
                        new Error(
                            'Failed to move some selected items to trash.',
                        ),
                    ),
                onCancel: () => reject(new Error('Request cancelled.')),
            },
        );
    });
}

export async function moveSelectionToTrash({
    files,
    folders,
}: {
    files: TrashItem[];
    folders: TrashItem[];
}): Promise<void> {
    const total = files.length + folders.length;
    if (total === 0) {
        return;
    }

    await requestBulkTrash(
        files.map((file) => file.public_id),
        folders.map((folder) => folder.public_id),
    );

    if (total > 0) {
        emitToast({
            kind: 'success',
            message: `${total} item${total === 1 ? '' : 's'} moved to trash.`,
            durationMs: 4500,
        });
    }
}
