import { emitToast } from '@/lib/toast';

type SelectionItem = {
    public_id: string;
};

export function downloadSelectionFiles({
    files,
}: {
    files: SelectionItem[];
}): void {
    if (files.length === 0 || typeof window === 'undefined') {
        return;
    }

    const params = new URLSearchParams();
    files.forEach((file) => {
        params.append('files[]', file.public_id);
    });

    window.open(
        `/selection/download?${params.toString()}`,
        '_blank',
        'noopener,noreferrer',
    );

    emitToast({
        kind: 'success',
        message: `Preparing download for ${files.length} file${files.length === 1 ? '' : 's'}.`,
        durationMs: 3500,
    });
}
