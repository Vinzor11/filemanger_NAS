import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    FileCheck2,
    Leaf,
    ShieldCheck,
    Workflow,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard, login } from '@/routes';

const governancePillars = [
    {
        title: 'Policy Compliance',
        description:
            'Controlled workflows and traceable approvals aligned to institutional requirements.',
        icon: FileCheck2,
        badge: 'Info',
        badgeVariant: 'info' as const,
    },
    {
        title: 'Data Integrity',
        description:
            'Versioned records, immutable logs, and clear ownership across departments.',
        icon: ShieldCheck,
        badge: 'Success',
        badgeVariant: 'success' as const,
    },
    {
        title: 'Sustainable Operations',
        description:
            'Structured reporting for environmental initiatives and program outcomes.',
        icon: Leaf,
        badge: 'Warning',
        badgeVariant: 'warning' as const,
    },
];

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    const { auth } = usePage().props;
    const appName = import.meta.env.VITE_APP_NAME || 'Filemanager NAS';

    return (
        <>
            <Head title="Welcome" />

            <div className="min-h-screen bg-background text-foreground">
                <header className="border-b border-sidebar-border/80 bg-warning text-sidebar-foreground">
                    <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 md:px-6">
                        <h1 className="text-sm font-semibold tracking-wide">
                            {appName}
                        </h1>
                        <nav className="flex items-center gap-2">
                            {auth.user ? (
                                <Button asChild size="sm">
                                    <Link href={dashboard()} prefetch>
                                        Open dashboard
                                    </Link>
                                </Button>
                            ) : (
                                <>
                                    <Button
                                        asChild
                                        size="sm"
                                        variant="outline"
                                        className="border-sidebar-foreground/30 bg-transparent text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-foreground"
                                    >
                                        <Link href={login()}>Log in</Link>
                                    </Button>
                                    {canRegister && (
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="secondary"
                                        >
                                            <Link href="/register">
                                                Register
                                            </Link>
                                        </Button>
                                    )}
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                <main className="mx-auto max-w-6xl px-4 py-10 md:px-6 md:py-12">
                    <section className="grid gap-6 lg:grid-cols-[1.25fr_1fr]">
                        <Card className="h-full">
                            <CardHeader>
                                <Badge variant="info">
                                    Institutional Standard
                                </Badge>
                                <CardTitle className="mt-2 text-2xl">
                                    Environmental File Governance Platform
                                </CardTitle>
                                <CardDescription className="max-w-2xl">
                                    Secure file management for regulated teams
                                    with a clear hierarchy, consistent controls,
                                    and auditable operational records.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex flex-col gap-3 sm:flex-row">
                                    <Button
                                        asChild
                                        className="w-full sm:w-auto"
                                    >
                                        <Link href={dashboard()} prefetch>
                                            Go to dashboard
                                            <ArrowRight className="size-4" />
                                        </Link>
                                    </Button>
                                    {!auth.user && (
                                        <Button
                                            asChild
                                            className="w-full sm:w-auto"
                                            variant="secondary"
                                        >
                                            <Link href={login()}>
                                                Access portal
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                                <div className="mt-6 grid gap-3 sm:grid-cols-3">
                                    <div className="rounded-md border border-border bg-background p-3">
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            Availability
                                        </p>
                                        <p className="mt-1 text-sm font-semibold">
                                            24/7 service window
                                        </p>
                                    </div>
                                    <div className="rounded-md border border-border bg-background p-3">
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            Retention
                                        </p>
                                        <p className="mt-1 text-sm font-semibold">
                                            Policy-managed archive
                                        </p>
                                    </div>
                                    <div className="rounded-md border border-border bg-background p-3">
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            Oversight
                                        </p>
                                        <p className="mt-1 text-sm font-semibold">
                                            Continuous audit trail
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <div className="grid gap-4">
                            {governancePillars.map((pillar) => (
                                <Card key={pillar.title}>
                                    <CardHeader className="gap-2">
                                        <div className="flex items-center justify-between">
                                            <pillar.icon className="size-5 text-primary" />
                                            <Badge
                                                variant={pillar.badgeVariant}
                                            >
                                                {pillar.badge}
                                            </Badge>
                                        </div>
                                        <CardTitle className="text-base">
                                            {pillar.title}
                                        </CardTitle>
                                        <CardDescription>
                                            {pillar.description}
                                        </CardDescription>
                                    </CardHeader>
                                </Card>
                            ))}
                        </div>
                    </section>

                    <section className="mt-6">
                        <Card>
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <Workflow className="size-5 text-secondary" />
                                    <CardTitle className="text-lg">
                                        Program Activity
                                    </CardTitle>
                                </div>
                                <CardDescription>
                                    Structured status view with mobile-friendly
                                    table-to-card behavior.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <table className="table-card-mobile">
                                        <thead>
                                            <tr>
                                                <th>Department</th>
                                                <th>Program</th>
                                                <th>Status</th>
                                                <th>Updated</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td data-label="Department">
                                                    Urban Planning
                                                </td>
                                                <td data-label="Program">
                                                    Waste Stream Audit
                                                </td>
                                                <td data-label="Status">
                                                    <Badge variant="success">
                                                        Verified
                                                    </Badge>
                                                </td>
                                                <td data-label="Updated">
                                                    Feb 19, 2026
                                                </td>
                                            </tr>
                                            <tr>
                                                <td data-label="Department">
                                                    Water Resources
                                                </td>
                                                <td data-label="Program">
                                                    Basin Compliance Review
                                                </td>
                                                <td data-label="Status">
                                                    <Badge variant="info">
                                                        In Progress
                                                    </Badge>
                                                </td>
                                                <td data-label="Updated">
                                                    Feb 18, 2026
                                                </td>
                                            </tr>
                                            <tr>
                                                <td data-label="Department">
                                                    Infrastructure
                                                </td>
                                                <td data-label="Program">
                                                    Emissions Disclosure
                                                </td>
                                                <td data-label="Status">
                                                    <Badge variant="warning">
                                                        Under Review
                                                    </Badge>
                                                </td>
                                                <td data-label="Updated">
                                                    Feb 17, 2026
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>
                    </section>
                </main>
            </div>
        </>
    );
}
