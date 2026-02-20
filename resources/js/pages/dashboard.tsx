import { Head } from '@inertiajs/react';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

export default function Dashboard() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-lg p-4">
                <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                    <div className="relative aspect-video overflow-hidden rounded-lg border border-border bg-card shadow-soft-sm">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-primary/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-lg border border-border bg-card shadow-soft-sm">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-primary/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-lg border border-border bg-card shadow-soft-sm">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-primary/20" />
                    </div>
                </div>
                <div className="relative min-h-[100vh] flex-1 overflow-hidden rounded-lg border border-border bg-card shadow-soft-sm md:min-h-min">
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-primary/20" />
                </div>
            </div>
        </AppLayout>
    );
}
