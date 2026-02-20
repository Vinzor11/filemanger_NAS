import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AuthLayout from '@/layouts/auth-layout';

type PendingApprovalProps = {
    status?: string;
};

export default function PendingApproval({ status }: PendingApprovalProps) {
    const page = usePage();
    const flashStatus = (page.props as { flash?: { status?: string } }).flash
        ?.status;

    return (
        <AuthLayout
            title="Approval in progress"
            description="Your account request was submitted and awaits admin approval."
        >
            <Head title="Pending Approval" />

            <Card>
                <CardHeader>
                    <CardTitle>Account Pending</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4 text-sm text-muted-foreground">
                    <p>
                        {status ??
                            flashStatus ??
                            'Your registration was received.'}
                    </p>
                    <p>
                        You will be able to sign in after an administrator
                        approves your account.
                    </p>
                    <Button asChild variant="outline">
                        <Link href="/login">Back to login</Link>
                    </Button>
                </CardContent>
            </Card>
        </AuthLayout>
    );
}
