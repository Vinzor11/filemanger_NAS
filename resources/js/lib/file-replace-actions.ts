import { router } from '@inertiajs/react';
import { emitToast } from '@/lib/toast';

function firstErrorMessage(errors: Record<string, string | string[]>): string {
    for (const value of Object.values(errors)) {
        if (Array.isArray(value) && value.length > 0) {
            return String(value[0]);
        }
        if (typeof value === 'string' && value.trim() !== '') {
            return value.trim();
        }
    }

    return 'Unable to replace file.';
}

export function promptReplaceFile(
    filePublicId: string,
    fileName: string,
): void {
    if (typeof document === 'undefined') {
        return;
    }

    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = false;

    input.onchange = () => {
        const selectedFile = input.files?.[0];
        if (!selectedFile) {
            return;
        }

        router.patch(
            `/files/${filePublicId}/replace`,
            {
                file: selectedFile,
            },
            {
                preserveScroll: true,
                headers: {
                    'X-Idempotency-Key': crypto.randomUUID(),
                },
                onSuccess: () => {
                    emitToast({
                        kind: 'success',
                        message: `Replaced ${fileName}.`,
                        durationMs: 3500,
                    });
                },
                onError: (errors) => {
                    emitToast({
                        kind: 'error',
                        message: firstErrorMessage(
                            errors as Record<string, string | string[]>,
                        ),
                        durationMs: 4500,
                    });
                },
            },
        );
    };

    input.click();
}
