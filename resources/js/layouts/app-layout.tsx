import { usePage } from '@inertiajs/react';
import { echoIsConfigured } from '@laravel/echo-react';
import { IncomingCalls } from '@/components/calls/incoming-calls';
import { RealtimeBridge } from '@/components/realtime-bridge';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const userId = usePage().props.auth.user?.id;

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {userId !== undefined && echoIsConfigured() && (
                <>
                    <RealtimeBridge key={userId} userId={userId} />
                    <IncomingCalls key={`calls-${userId}`} />
                </>
            )}
            {children}
        </AppLayoutTemplate>
    );
}
