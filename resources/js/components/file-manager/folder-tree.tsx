import { Link } from '@inertiajs/react';
import { Folder } from 'lucide-react';
import { Skeleton } from '@/components/ui/skeleton';

type FolderNode = {
    public_id: string;
    name: string;
};

type FolderTreeProps = {
    folders: FolderNode[];
    title?: string;
    loading?: boolean;
};

export function FolderTree({
    folders,
    title = 'Folders',
    loading = false,
}: FolderTreeProps) {
    if (loading) {
        return (
            <div className="rounded-lg border bg-card p-4">
                <Skeleton className="mb-3 h-4 w-28" />
                <div className="space-y-2">
                    {Array.from(
                        { length: 7 },
                        (_, index) => `folder-skeleton-${index}`,
                    ).map((key) => (
                        <div key={key} className="flex items-center gap-2">
                            <Skeleton className="h-4 w-4 rounded-sm" />
                            <Skeleton className="h-4 w-36" />
                        </div>
                    ))}
                </div>
            </div>
        );
    }

    return (
        <div className="rounded-lg border bg-card p-4">
            <h3 className="mb-3 text-sm font-semibold text-foreground">
                {title}
            </h3>
            {folders.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    No folders found.
                </p>
            ) : (
                <ul className="space-y-2">
                    {folders.map((folder) => (
                        <li key={folder.public_id}>
                            <Link
                                href={`/folders/${folder.public_id}`}
                                className="inline-flex items-center gap-2 text-sm text-primary hover:underline"
                            >
                                <Folder className="h-4 w-4" />
                                {folder.name}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
