import { Head, useForm, usePage } from '@inertiajs/react';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { BreadcrumbItem } from '@/types';

type ProfilePageProps = {
    auth: {
        user: {
            email: string | null;
        } | null;
    };
    mustVerifyEmail: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Profile', href: '/settings/profile' },
];

export default function ProfileSettings({ mustVerifyEmail }: ProfilePageProps) {
    const page = usePage<ProfilePageProps>();

    const form = useForm({
        email: page.props.auth.user?.email ?? '',
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profile settings" />
            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Profile information"
                        description="Update your account email address."
                    />

                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.patch('/settings/profile', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                autoComplete="email"
                                value={form.data.email}
                                onChange={(event) =>
                                    form.setData('email', event.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.email} />
                        </div>

                        {mustVerifyEmail ? (
                            <p className="text-sm text-muted-foreground">
                                Email verification is enabled for your account.
                            </p>
                        ) : null}

                        <Button type="submit" disabled={form.processing}>
                            Save changes
                        </Button>
                    </form>
                </div>

                <DeleteUser />
            </SettingsLayout>
        </AppLayout>
    );
}
