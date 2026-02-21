import { Head } from '@inertiajs/react';
import { useMemo } from 'react';
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

type BreadcrumbNode = {
    public_id: string;
    name: string;
};

type PageProps = {
    folder: FolderRow;
    breadcrumbTrail: BreadcrumbNode[];
    children: FolderRow[];
    files: {
        data: FileRow[];
    };
};

export default function TrashFolder({
    folder,
    breadcrumbTrail,
    children,
    files,
}: PageProps) {
    const isPageLoading = usePageLoading();
    const [layoutMode, setLayoutMode] = useFileLayoutMode(
        'explorer-layout-trash',
    );

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            { title: 'Trash', href: '/trash' },
            ...breadcrumbTrail.map((item) => ({
                title: item.name,
                href: `/trash/folders/${item.public_id}`,
            })),
        ],
        [breadcrumbTrail],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Trash - ${folder.name}`} />
            <div className="space-y-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold">{folder.name}</h1>
                    <LayoutModeToggle
                        value={layoutMode}
                        onValueChange={setLayoutMode}
                    />
                </div>
                <TrashView
                    folders={children}
                    files={files.data}
                    loading={isPageLoading}
                    layoutMode={layoutMode}
                />
            </div>
        </AppLayout>
    );
}
