import { Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { FileRow } from './file-table';

type FileCardProps = {
    file: FileRow;
};

export function FileCard({ file }: FileCardProps) {
    return (
        <div className="rounded-lg border bg-card p-4">
            <div className="text-sm font-medium">{file.original_name}</div>
            <div className="mt-1 text-xs text-muted-foreground">
                {file.extension ?? 'file'} -{' '}
                {Math.max(1, Math.round(file.size_bytes / 1024))} KB
            </div>
            <div className="mt-3">
                <Button size="sm" variant="outline" asChild>
                    <Link href={`/files/${file.public_id}/download`}>
                        <Download className="mr-2 h-4 w-4" />
                        Download
                    </Link>
                </Button>
            </div>
        </div>
    );
}
