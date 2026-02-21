import { Head } from '@inertiajs/react';
import {
    type FileRow,
    type FolderRow,
} from '@/components/file-manager/file-table';
import { LayoutModeToggle } from '@/components/file-manager/layout-mode-toggle';
import { TrashView } from '@/components/file-manager/trash-view';
import { useFileLayoutMode } from '@/hooks/use-file-layout-mode';
import { usePageLoading } from '@/hooks/use-page-loading';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type PageProps = {
    folders: { data: FolderRow[] };
    files: { data: FileRow[] };
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Trash', href: '/trash' }];

export default function Trash({ folders, files }: PageProps) {
    const isPageLoading = usePageLoading();
    const [layoutMode, setLayoutMode] = useFileLayoutMode(
        'explorer-layout-trash',
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Trash" />
            <div className="space-y-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold">Trash</h1>
                    <LayoutModeToggle
                        value={layoutMode}
                        onValueChange={setLayoutMode}
                    />
                </div>
                <TrashView
                    folders={folders.data}
                    files={files.data}
                    loading={isPageLoading}
                    layoutMode={layoutMode}
                />
            </div>
        </AppLayout>
    );
}
