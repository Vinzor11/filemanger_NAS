import { Badge } from '@/components/ui/badge';

type PendingApprovalBadgeProps = {
    status: 'pending' | 'active' | 'rejected' | 'blocked';
};

export function PendingApprovalBadge({ status }: PendingApprovalBadgeProps) {
    const variant =
        status === 'active'
            ? 'default'
            : status === 'pending'
              ? 'secondary'
              : status === 'blocked'
                ? 'destructive'
                : 'outline';

    return <Badge variant={variant}>{status}</Badge>;
}
