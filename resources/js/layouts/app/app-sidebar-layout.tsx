import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {/* Inertia resets and restores the scroll position of elements marked scroll-region. */}
                <div
                    scroll-region=""
                    className="flex min-h-0 flex-1 flex-col overflow-y-auto"
                >
                    {children}
                </div>
            </AppContent>
        </AppShell>
    );
}
