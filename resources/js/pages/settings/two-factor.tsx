import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { BreadcrumbItem } from '@/types';

type TwoFactorPageProps = {
    twoFactorEnabled: boolean;
    requiresConfirmation: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Two-Factor Auth', href: '/settings/two-factor' },
];

export default function TwoFactorSettings({
    twoFactorEnabled,
    requiresConfirmation,
}: TwoFactorPageProps) {
    const [isEnabled, setIsEnabled] = useState(twoFactorEnabled);
    const [setupModalOpen, setSetupModalOpen] = useState(false);

    const {
        qrCodeSvg,
        manualSetupKey,
        recoveryCodesList,
        errors,
        clearErrors,
        clearSetupData,
        fetchSetupData,
        fetchRecoveryCodes,
    } = useTwoFactorAuth();

    const enableTwoFactor = () => {
        router.post(
            '/user/two-factor-authentication',
            {},
            {
                preserveScroll: true,
                onSuccess: async () => {
                    setIsEnabled(true);
                    clearErrors();
                    setSetupModalOpen(true);
                    await fetchSetupData();
                },
            },
        );
    };

    const disableTwoFactor = () => {
        router.delete('/user/two-factor-authentication', {
            preserveScroll: true,
            onSuccess: () => {
                setIsEnabled(false);
                clearSetupData();
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Two-factor settings" />
            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Two-factor authentication"
                        description="Add an extra layer of security to your account."
                    />

                    <div className="rounded-lg border bg-card p-4">
                        <div className="mb-4 text-sm text-muted-foreground">
                            {isEnabled
                                ? 'Two-factor authentication is currently enabled.'
                                : 'Two-factor authentication is currently disabled.'}
                        </div>

                        {isEnabled ? (
                            <Button
                                variant="outline"
                                onClick={disableTwoFactor}
                            >
                                Disable two-factor authentication
                            </Button>
                        ) : (
                            <Button onClick={enableTwoFactor}>
                                Enable two-factor authentication
                            </Button>
                        )}
                    </div>

                    {isEnabled ? (
                        <TwoFactorRecoveryCodes
                            recoveryCodesList={recoveryCodesList}
                            fetchRecoveryCodes={fetchRecoveryCodes}
                            errors={errors}
                        />
                    ) : null}
                </div>

                <TwoFactorSetupModal
                    isOpen={setupModalOpen}
                    onClose={() => setSetupModalOpen(false)}
                    requiresConfirmation={requiresConfirmation}
                    twoFactorEnabled={isEnabled}
                    qrCodeSvg={qrCodeSvg}
                    manualSetupKey={manualSetupKey}
                    clearSetupData={clearSetupData}
                    fetchSetupData={fetchSetupData}
                    errors={errors}
                />
            </SettingsLayout>
        </AppLayout>
    );
}
