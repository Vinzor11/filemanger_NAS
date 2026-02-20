import { Head } from '@inertiajs/react';
import { TableCardSkeleton } from '@/components/ui/page-loading-skeletons';
import { usePageLoading } from '@/hooks/use-page-loading';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type AuditRow = {
    id: number;
    action: string;
    entity_type: string;
    entity_id: number | null;
    ip_address: string | null;
    created_at: string;
    actor?: { email: string | null } | null;
    meta_json?: Record<string, unknown> | null;
};

type PageProps = {
    logs: {
        data: AuditRow[];
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Audit Logs', href: '/admin/audit-logs' },
];

export default function AuditLogs({ logs }: PageProps) {
    const isPageLoading = usePageLoading();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit Logs" />
            <div className="space-y-4 p-4 md:p-6">
                <h1 className="text-xl font-semibold">Audit Logs</h1>

                {isPageLoading ? (
                    <TableCardSkeleton columns={6} rows={8} />
                ) : (
                    <div className="overflow-x-auto rounded-lg border bg-card">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs tracking-wide text-muted-foreground uppercase">
                                <tr>
                                    <th className="px-4 py-3">Timestamp</th>
                                    <th className="px-4 py-3">Actor</th>
                                    <th className="px-4 py-3">Action</th>
                                    <th className="px-4 py-3">Entity</th>
                                    <th className="px-4 py-3">IP</th>
                                    <th className="px-4 py-3">Meta</th>
                                </tr>
                            </thead>
                            <tbody>
                                {logs.data.map((log) => (
                                    <tr
                                        key={log.id}
                                        className="border-t align-top"
                                    >
                                        <td className="px-4 py-3">
                                            {new Date(
                                                log.created_at,
                                            ).toLocaleString()}
                                        </td>
                                        <td className="px-4 py-3">
                                            {log.actor?.email ??
                                                'system/public'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {log.action}
                                        </td>
                                        <td className="px-4 py-3">
                                            {log.entity_type}#
                                            {log.entity_id ?? '-'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {log.ip_address ?? '-'}
                                        </td>
                                        <td className="max-w-sm px-4 py-3 text-xs text-muted-foreground">
                                            <pre className="whitespace-pre-wrap">
                                                {JSON.stringify(
                                                    log.meta_json ?? {},
                                                    null,
                                                    2,
                                                )}
                                            </pre>
                                        </td>
                                    </tr>
                                ))}
                                {!logs.data.length ? (
                                    <tr>
                                        <td
                                            className="px-4 py-6 text-center text-sm text-muted-foreground"
                                            colSpan={6}
                                        >
                                            No audit records yet.
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
