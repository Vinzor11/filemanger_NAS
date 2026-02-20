import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Password', href: '/settings/password' },
];

export default function PasswordSettings() {
    const form = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Password settings" />
            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Update password"
                        description="Use a strong password to protect your account."
                    />

                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.put('/settings/password', {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="current_password">
                                Current password
                            </Label>
                            <Input
                                id="current_password"
                                type="password"
                                autoComplete="current-password"
                                value={form.data.current_password}
                                onChange={(event) =>
                                    form.setData(
                                        'current_password',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                            <InputError
                                message={form.errors.current_password}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">New password</Label>
                            <Input
                                id="password"
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password}
                                onChange={(event) =>
                                    form.setData('password', event.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                Confirm new password
                            </Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password_confirmation}
                                onChange={(event) =>
                                    form.setData(
                                        'password_confirmation',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                            <InputError
                                message={form.errors.password_confirmation}
                            />
                        </div>

                        <Button type="submit" disabled={form.processing}>
                            Update password
                        </Button>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
