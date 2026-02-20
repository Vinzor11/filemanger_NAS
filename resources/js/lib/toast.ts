export type AppToastKind = 'success' | 'error';

export type AppToastPayload = {
    kind: AppToastKind;
    message: string;
    durationMs?: number;
};

export const APP_TOAST_EVENT = 'app:toast';

export function emitToast(payload: AppToastPayload): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.dispatchEvent(
        new CustomEvent<AppToastPayload>(APP_TOAST_EVENT, {
            detail: payload,
        }),
    );
}
