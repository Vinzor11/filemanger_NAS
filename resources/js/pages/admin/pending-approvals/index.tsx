import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PendingApprovalBadge } from '@/components/file-manager/pending-approval-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TableCardSkeleton } from '@/components/ui/page-loading-skeletons';
import { Spinner } from '@/components/ui/spinner';
import { usePageLoading } from '@/hooks/use-page-loading';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type PendingUser = {
    public_id: string;
    email: string | null;
    status: 'pending' | 'active' | 'rejected' | 'blocked';
    created_at: string;
    employee?: {
        employee_no: string;
        first_name: string;
        last_name: string;
        department?: { name: string } | null;
    } | null;
};

type PageProps = {
    pendingUsers: {
        data: PendingUser[];
    };
    roles: string[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pending Approvals', href: '/admin/approvals' },
];

export default function PendingApprovals({ pendingUsers, roles }: PageProps) {
    const isPageLoading = usePageLoading();
    const [rejectReason, setRejectReason] = useState<Record<string, string>>(
        {},
    );
    const [selectedRoles, setSelectedRoles] = useState<Record<string, string>>(
        {},
    );
    const [actionInFlight, setActionInFlight] = useState<string | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pending Approvals" />
            <div className="space-y-4 p-4 md:p-6">
                <h1 className="text-xl font-semibold">Pending Approvals</h1>

                {isPageLoading ? (
                    <TableCardSkeleton columns={6} rows={8} />
                ) : (
                    <div className="overflow-x-auto rounded-lg border bg-card">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs tracking-wide text-muted-foreground uppercase">
                                <tr>
                                    <th className="px-4 py-3">Employee</th>
                                    <th className="px-4 py-3">Email</th>
                                    <th className="px-4 py-3">Status</th>
                                    <th className="px-4 py-3">Requested</th>
                                    <th className="px-4 py-3">Role</th>
                                    <th className="px-4 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {pendingUsers.data.map((user) => {
                                    const approvingKey = `approve:${user.public_id}`;
                                    const rejectingKey = `reject:${user.public_id}`;
                                    const isApproving =
                                        actionInFlight === approvingKey;
                                    const isRejecting =
                                        actionInFlight === rejectingKey;

                                    return (
                                        <tr
                                            key={user.public_id}
                                            className="border-t align-top"
                                        >
                                            <td className="px-4 py-3">
                                                <div className="font-medium">
                                                    {user.employee?.first_name}{' '}
                                                    {user.employee?.last_name}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {user.employee?.employee_no}{' '}
                                                    -{' '}
                                                    {user.employee?.department
                                                        ?.name ??
                                                        'No department'}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                {user.email ?? '-'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <PendingApprovalBadge
                                                    status={user.status}
                                                />
                                            </td>
                                            <td className="px-4 py-3">
                                                {new Date(
                                                    user.created_at,
                                                ).toLocaleString()}
                                            </td>
                                            <td className="px-4 py-3">
                                                <select
                                                    value={
                                                        selectedRoles[
                                                            user.public_id
                                                        ] ?? 'Employee'
                                                    }
                                                    onChange={(event) =>
                                                        setSelectedRoles(
                                                            (prev) => ({
                                                                ...prev,
                                                                [user.public_id]:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                    className="h-9 rounded-md border bg-background px-2 text-sm"
                                                    disabled={
                                                        isApproving ||
                                                        isRejecting
                                                    }
                                                >
                                                    {roles.map((role) => (
                                                        <option
                                                            key={role}
                                                            value={role}
                                                        >
                                                            {role}
                                                        </option>
                                                    ))}
                                                </select>
                                            </td>
                                            <td className="space-y-2 px-4 py-3">
                                                <Button
                                                    size="sm"
                                                    disabled={
                                                        isApproving ||
                                                        isRejecting
                                                    }
                                                    onClick={() => {
                                                        setActionInFlight(
                                                            approvingKey,
                                                        );
                                                        router.post(
                                                            `/admin/approvals/${user.public_id}/approve`,
                                                            {
                                                                roles: [
                                                                    selectedRoles[
                                                                        user
                                                                            .public_id
                                                                    ] ??
                                                                        'Employee',
                                                                ],
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                                headers: {
                                                                    'X-Idempotency-Key':
                                                                        crypto.randomUUID(),
                                                                },
                                                                onFinish: () =>
                                                                    setActionInFlight(
                                                                        null,
                                                                    ),
                                                            },
                                                        );
                                                    }}
                                                >
                                                    {isApproving ? (
                                                        <>
                                                            <Spinner className="size-4" />
                                                            Approving...
                                                        </>
                                                    ) : (
                                                        'Approve'
                                                    )}
                                                </Button>
                                                <div className="space-y-1">
                                                    <Label className="text-xs">
                                                        Rejection reason
                                                    </Label>
                                                    <Input
                                                        value={
                                                            rejectReason[
                                                                user.public_id
                                                            ] ?? ''
                                                        }
                                                        onChange={(event) =>
                                                            setRejectReason(
                                                                (prev) => ({
                                                                    ...prev,
                                                                    [user.public_id]:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                }),
                                                            )
                                                        }
                                                        placeholder="Reason"
                                                        disabled={
                                                            isApproving ||
                                                            isRejecting
                                                        }
                                                    />
                                                </div>
                                                <Button
                                                    size="sm"
                                                    variant="destructive"
                                                    disabled={
                                                        isApproving ||
                                                        isRejecting
                                                    }
                                                    onClick={() => {
                                                        setActionInFlight(
                                                            rejectingKey,
                                                        );
                                                        router.post(
                                                            `/admin/approvals/${user.public_id}/reject`,
                                                            {
                                                                rejection_reason:
                                                                    rejectReason[
                                                                        user
                                                                            .public_id
                                                                    ] ??
                                                                    'Not eligible',
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                                headers: {
                                                                    'X-Idempotency-Key':
                                                                        crypto.randomUUID(),
                                                                },
                                                                onFinish: () =>
                                                                    setActionInFlight(
                                                                        null,
                                                                    ),
                                                            },
                                                        );
                                                    }}
                                                >
                                                    {isRejecting ? (
                                                        <>
                                                            <Spinner className="size-4" />
                                                            Rejecting...
                                                        </>
                                                    ) : (
                                                        'Reject'
                                                    )}
                                                </Button>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {!pendingUsers.data.length ? (
                                    <tr>
                                        <td
                                            className="px-4 py-6 text-center text-sm text-muted-foreground"
                                            colSpan={6}
                                        >
                                            No pending approvals.
                                        </td>
                                    </tr>
                                ) : null}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
