import { emitToast } from '@/lib/toast';

type SelectionItem = {
    public_id: string;
};

export function downloadSelectionFiles({
    files,
    folders,
}: {
    files: SelectionItem[];
    folders: SelectionItem[];
}): void {
    const totalItems = files.length + folders.length;

    if (totalItems === 0 || typeof window === 'undefined') {
        return;
    }

    const params = new URLSearchParams();
    files.forEach((file) => {
        params.append('files[]', file.public_id);
    });
    folders.forEach((folder) => {
        params.append('folders[]', folder.public_id);
    });

    window.open(
        `/selection/download?${params.toString()}`,
        '_blank',
        'noopener,noreferrer',
    );

    emitToast({
        kind: 'success',
        message: `Preparing download for ${totalItems} item${totalItems === 1 ? '' : 's'}.`,
        durationMs: 3500,
    });
}
