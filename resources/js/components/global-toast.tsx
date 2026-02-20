import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { APP_TOAST_EVENT, type AppToastPayload } from '@/lib/toast';
import { cn } from '@/lib/utils';

type ToastKind = 'success' | 'error';

type ToastMessage = {
    id: number;
    kind: ToastKind;
    message: string;
};

type SharedPageProps = {
    requestId?: string;
    flash?: {
        status?: string;
        error?: string;
    };
    errors?: Record<string, string | string[]>;
};

const sensitiveMessagePattern =
    /(sqlstate|stack trace|exception|vendor[\\/]|select\s+.+\s+from|insert\s+into|undefined index|syntax error| at line \d+)/i;

function sanitizeToastMessage(message: string): string {
    const trimmed = message.trim();

    if (trimmed.length === 0) {
        return '';
    }

    if (trimmed.length > 200 || sensitiveMessagePattern.test(trimmed)) {
        return 'Operation failed. Please try again.';
    }

    return trimmed;
}

function getFirstError(
    errors?: Record<string, string | string[]>,
): string | null {
    if (!errors) {
        return null;
    }

    for (const value of Object.values(errors)) {
        if (Array.isArray(value)) {
            const first = value.find(
                (item) => typeof item === 'string' && item.trim().length > 0,
            );
            if (first) {
                return first;
            }
            continue;
        }

        if (typeof value === 'string' && value.trim().length > 0) {
            return value;
        }
    }

    return null;
}

export function GlobalToast() {
    const page = usePage<SharedPageProps>();
    const [toasts, setToasts] = useState<ToastMessage[]>([]);
    const timersRef = useRef<Map<number, ReturnType<typeof setTimeout>>>(
        new Map(),
    );
    const lastStatusKeyRef = useRef<string | null>(null);
    const lastErrorKeyRef = useRef<string | null>(null);

    const requestKey = String(page.props.requestId ?? 'request');
    const statusMessage = page.props.flash?.status ?? null;
    const flashErrorMessage = page.props.flash?.error ?? null;
    const firstValidationError = getFirstError(page.props.errors);
    const resolvedErrorMessage = flashErrorMessage ?? firstValidationError;

    const removeToast = useCallback((id: number) => {
        const timer = timersRef.current.get(id);
        if (timer) {
            clearTimeout(timer);
            timersRef.current.delete(id);
        }

        setToasts((current) => current.filter((toast) => toast.id !== id));
    }, []);

    const pushToast = useCallback(
        (kind: ToastKind, message: string, durationMs = 5000) => {
            const sanitizedMessage = sanitizeToastMessage(message);

            if (!sanitizedMessage) {
                return;
            }

            const id = Date.now() + Math.floor(Math.random() * 1000);
            setToasts((current) =>
                [...current, { id, kind, message: sanitizedMessage }].slice(-4),
            );

            const timer = setTimeout(() => {
                removeToast(id);
            }, durationMs);
            timersRef.current.set(id, timer);
        },
        [removeToast],
    );

    useEffect(() => {
        if (!statusMessage) {
            return;
        }

        const statusKey = `${requestKey}:status:${statusMessage}`;
        if (lastStatusKeyRef.current === statusKey) {
            return;
        }

        lastStatusKeyRef.current = statusKey;

        const timer = setTimeout(() => {
            pushToast('success', statusMessage);
        }, 0);

        return () => clearTimeout(timer);
    }, [statusMessage, requestKey, pushToast]);

    useEffect(() => {
        const listener = (event: Event) => {
            const customEvent = event as CustomEvent<AppToastPayload>;
            if (!customEvent.detail) {
                return;
            }

            const { kind, message, durationMs } = customEvent.detail;
            pushToast(kind, message, durationMs);
        };

        window.addEventListener(APP_TOAST_EVENT, listener as EventListener);

        return () => {
            window.removeEventListener(
                APP_TOAST_EVENT,
                listener as EventListener,
            );
        };
    }, [pushToast]);

    useEffect(() => {
        if (!resolvedErrorMessage) {
            return;
        }

        const errorKey = `${requestKey}:error:${resolvedErrorMessage}`;
        if (lastErrorKeyRef.current === errorKey) {
            return;
        }

        lastErrorKeyRef.current = errorKey;

        const timer = setTimeout(() => {
            pushToast('error', resolvedErrorMessage);
        }, 0);

        return () => clearTimeout(timer);
    }, [resolvedErrorMessage, requestKey, pushToast]);

    useEffect(
        () => () => {
            for (const timer of timersRef.current.values()) {
                clearTimeout(timer);
            }
            timersRef.current.clear();
        },
        [],
    );

    const hasToasts = useMemo(() => toasts.length > 0, [toasts.length]);

    if (!hasToasts) {
        return null;
    }

    return (
        <div className="pointer-events-none fixed top-4 right-4 z-[100] flex w-full max-w-md flex-col gap-3">
            {toasts.map((toast) => (
                <div
                    key={toast.id}
                    className={cn(
                        'pointer-events-auto flex items-start gap-3 rounded-lg border bg-card px-4 py-3 text-[15px] leading-6 shadow-lg',
                        toast.kind === 'success'
                            ? 'border-emerald-200 text-emerald-700'
                            : 'border-destructive/30 text-destructive',
                    )}
                    role="status"
                    aria-live="polite"
                >
                    <p className="flex-1 font-medium">{toast.message}</p>
                    <button
                        type="button"
                        className="mt-0.5 text-muted-foreground transition hover:text-foreground"
                        onClick={() => removeToast(toast.id)}
                        aria-label="Dismiss notification"
                    >
                        x
                    </button>
                </div>
            ))}
        </div>
    );
}
