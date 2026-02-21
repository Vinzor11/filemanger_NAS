import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarExplorerSearch } from '@/components/sidebar-explorer-search';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="grid h-16 shrink-0 grid-cols-[minmax(0,1fr)_minmax(0,1fr)] items-center gap-2 border-b border-border px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:grid-cols-[minmax(0,1fr)_minmax(0,52rem)_minmax(0,1fr)] md:px-4">
            <div className="flex min-w-0 items-center gap-2 overflow-hidden">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="flex justify-center">
                <SidebarExplorerSearch mode="header" className="w-full" />
            </div>
            <div className="hidden md:block" aria-hidden />
        </header>
    );
}
