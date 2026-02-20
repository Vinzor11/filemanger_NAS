import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const SKELETON_DELAY_MS = 300;

export function usePageLoading() {
    const [isPageLoading, setIsPageLoading] = useState(false);
    const activeVisitsRef = useRef(0);
    const isVisibleRef = useRef(false);
    const delayTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        const isTrackableVisit = (visit: { method: string; prefetch: boolean }) =>
            !visit.prefetch && visit.method.toLowerCase() === 'get';

        const setLoading = (value: boolean) => {
            if (isVisibleRef.current === value) {
                return;
            }

            isVisibleRef.current = value;
            setIsPageLoading(value);
        };

        const clearDelayTimer = () => {
            if (delayTimerRef.current !== null) {
                clearTimeout(delayTimerRef.current);
                delayTimerRef.current = null;
            }
        };

        const scheduleLoading = () => {
            if (delayTimerRef.current !== null || isVisibleRef.current) {
                return;
            }

            delayTimerRef.current = setTimeout(() => {
                delayTimerRef.current = null;

                if (activeVisitsRef.current > 0) {
                    setLoading(true);
                }
            }, SKELETON_DELAY_MS);
        };

        const stopLoading = () => {
            clearDelayTimer();
            setLoading(false);
        };

        const removeStart = router.on('start', (event) => {
            if (!isTrackableVisit(event.detail.visit)) {
                return;
            }

            activeVisitsRef.current += 1;
            scheduleLoading();
        });

        const removeFinish = router.on('finish', (event) => {
            if (!isTrackableVisit(event.detail.visit)) {
                return;
            }

            activeVisitsRef.current = Math.max(0, activeVisitsRef.current - 1);

            if (activeVisitsRef.current === 0) {
                stopLoading();
            }
        });

        const resetLoading = () => {
            activeVisitsRef.current = 0;
            stopLoading();
        };

        const removeCancel = router.on('cancel', resetLoading);
        const removeError = router.on('error', resetLoading);
        const removeNavigate = router.on('navigate', () => {
            if (activeVisitsRef.current === 0) {
                stopLoading();
            }
        });

        return () => {
            activeVisitsRef.current = 0;
            clearDelayTimer();
            removeStart();
            removeFinish();
            removeCancel();
            removeError();
            removeNavigate();
        };
    }, []);

    return isPageLoading;
}
