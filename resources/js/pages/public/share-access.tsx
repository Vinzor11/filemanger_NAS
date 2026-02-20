import { Head, useForm } from '@inertiajs/react';
import { GlobalToast } from '@/components/global-toast';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type ShareAccessProps = {
    exists: boolean;
    requires_password: boolean;
    is_accessible: boolean;
    file_name?: string;
    expires_at?: string | null;
    download_count?: number;
    max_downloads?: number | null;
    token: string;
};

export default function ShareAccess({
    exists,
    requires_password,
    is_accessible,
    file_name,
    expires_at,
    download_count,
    max_downloads,
    token,
}: ShareAccessProps) {
    const form = useForm({ password: '' });

    if (!exists) {
        return (
            <>
                <div className="mx-auto mt-20 max-w-xl p-4">
                    <Head title="Share Link" />
                    <Card>
                        <CardHeader>
                            <CardTitle>Invalid share link</CardTitle>
                        </CardHeader>
                        <CardContent>
                            This share link does not exist.
                        </CardContent>
                    </Card>
                </div>
                <GlobalToast />
            </>
        );
    }

    return (
        <>
            <div className="mx-auto mt-20 max-w-xl p-4">
                <Head title="Shared File" />
                <Card>
                    <CardHeader>
                        <CardTitle>Shared file access</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-sm">
                            File:{' '}
                            <span className="font-medium">{file_name}</span>
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Expires:{' '}
                            {expires_at
                                ? new Date(expires_at).toLocaleString()
                                : 'No expiry'}{' '}
                            - Downloads: {download_count ?? 0}/
                            {max_downloads ?? 'unlimited'}
                        </p>

                        {!is_accessible ? (
                            <p className="text-sm text-destructive">
                                This link is expired, revoked, or exhausted.
                            </p>
                        ) : null}

                        {requires_password ? (
                            <form
                                className="space-y-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(`/s/${token}/access`);
                                }}
                            >
                                <Label htmlFor="password">Password</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={form.data.password}
                                    onChange={(event) =>
                                        form.setData(
                                            'password',
                                            event.target.value,
                                        )
                                    }
                                />
                                <Button
                                    type="submit"
                                    disabled={form.processing || !is_accessible}
                                >
                                    Validate access
                                </Button>
                            </form>
                        ) : null}

                        <Button asChild disabled={!is_accessible}>
                            <a href={`/s/${token}/download`}>Download</a>
                        </Button>
                    </CardContent>
                </Card>
            </div>
            <GlobalToast />
        </>
    );
}
