import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

type Crumb = {
    label: string;
    href?: string;
};

type FileBreadcrumbsProps = {
    items: Crumb[];
};

export function FileBreadcrumbs({ items }: FileBreadcrumbsProps) {
    return (
        <nav className="flex items-center gap-1 text-xs text-muted-foreground">
            {items.map((item, index) => (
                <span
                    key={`${item.label}-${index}`}
                    className="inline-flex items-center gap-1"
                >
                    {item.href ? (
                        <Link
                            href={item.href}
                            className="hover:text-foreground hover:underline"
                        >
                            {item.label}
                        </Link>
                    ) : (
                        <span className="text-foreground">{item.label}</span>
                    )}
                    {index < items.length - 1 && (
                        <ChevronRight className="h-3 w-3" />
                    )}
                </span>
            ))}
        </nav>
    );
}
