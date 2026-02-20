import { Skeleton } from '@/components/ui/skeleton';

type TableCardSkeletonProps = {
    columns?: number;
    rows?: number;
};

type FormCardSkeletonProps = {
    fields?: number;
    columns?: 1 | 2 | 3;
};

type CardListSkeletonProps = {
    rows?: number;
};

const formGridClasses: Record<NonNullable<FormCardSkeletonProps['columns']>, string> = {
    1: '',
    2: 'md:grid-cols-2',
    3: 'md:grid-cols-3',
};

export function TableCardSkeleton({ columns = 6, rows = 8 }: TableCardSkeletonProps) {
    const headerCells = Array.from({ length: columns }, (_, index) => `table-header-${index}`);
    const bodyRows = Array.from({ length: rows }, (_, index) => `table-row-${index}`);

    return (
        <section className="overflow-x-auto rounded-lg border bg-card">
            <div className="min-w-[760px]">
                <div
                    className="grid border-b bg-muted/40 px-4 py-3"
                    style={{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }}
                >
                    {headerCells.map((key) => (
                        <div key={key} className="px-2">
                            <Skeleton className="h-3 w-20" />
                        </div>
                    ))}
                </div>

                {bodyRows.map((rowKey) => (
                    <div
                        key={rowKey}
                        className="grid border-b px-4 py-3 last:border-b-0"
                        style={{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }}
                    >
                        {headerCells.map((columnKey) => (
                            <div key={`${rowKey}-${columnKey}`} className="px-2">
                                <Skeleton className="h-4 w-full max-w-[180px]" />
                            </div>
                        ))}
                    </div>
                ))}
            </div>
        </section>
    );
}

export function FormCardSkeleton({ fields = 10, columns = 2 }: FormCardSkeletonProps) {
    const fieldKeys = Array.from({ length: fields }, (_, index) => `form-field-${index}`);

    return (
        <section className="rounded-lg border bg-card p-4">
            <Skeleton className="mb-4 h-4 w-40" />
            <div className={`grid gap-3 ${formGridClasses[columns]}`}>
                {fieldKeys.map((key) => (
                    <div key={key} className="space-y-2">
                        <Skeleton className="h-3 w-24" />
                        <Skeleton className="h-9 w-full" />
                    </div>
                ))}
                <div className={columns === 1 ? '' : 'md:col-span-2'}>
                    <Skeleton className="h-9 w-40" />
                </div>
            </div>
        </section>
    );
}

export function CardListSkeleton({ rows = 6 }: CardListSkeletonProps) {
    const rowKeys = Array.from({ length: rows }, (_, index) => `card-row-${index}`);

    return (
        <section className="rounded-lg border bg-card p-4">
            <Skeleton className="mb-4 h-4 w-40" />
            <div className="space-y-3">
                {rowKeys.map((key) => (
                    <div key={key} className="flex items-center justify-between rounded-md border p-3">
                        <div className="space-y-2">
                            <Skeleton className="h-4 w-40" />
                            <Skeleton className="h-3 w-28" />
                        </div>
                        <Skeleton className="h-8 w-20" />
                    </div>
                ))}
            </div>
        </section>
    );
}
