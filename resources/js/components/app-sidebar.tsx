import { Link } from '@inertiajs/react';
import { usePage } from '@inertiajs/react';
import {
    FileText,
    Folder,
    FolderOpen,
    LayoutGrid,
    Share2,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';
import AppLogo from './app-logo';

export function AppSidebar() {
    const page = usePage<{
        auth: {
            user: {
                permissions: string[];
            } | null;
        };
    }>();
    const permissions = page.props.auth.user?.permissions ?? [];

    const mainNavItems: NavItem[] = [
        { title: 'My Files', href: '/my-files', icon: FolderOpen },
        { title: 'Department Files', href: '/department-files', icon: Folder },
        { title: 'Shared With Me', href: '/shared-with-me', icon: Share2 },
        { title: 'Trash', href: '/trash', icon: FileText },
    ];

    if (permissions.includes('users.approve')) {
        mainNavItems.push({
            title: 'Approvals',
            href: '/admin/approvals',
            icon: Users,
        });
    }
    if (permissions.includes('employees.view')) {
        mainNavItems.push({
            title: 'Employees',
            href: '/admin/employees',
            icon: LayoutGrid,
        });
    }
    if (permissions.includes('audit.view')) {
        mainNavItems.push({
            title: 'Audit Logs',
            href: '/admin/audit-logs',
            icon: ShieldCheck,
        });
    }

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/my-files" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
