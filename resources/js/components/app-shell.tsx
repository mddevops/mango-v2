import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AppModuleProvider, LAYOUT_SIZES } from '@/components/app-sidebar';
import { SidebarProvider } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    children: ReactNode;
    variant?: AppVariant;
};

export function AppShell({ children, variant = 'sidebar' }: Props) {
    const isOpen = usePage().props.sidebarOpen;

    if (variant === 'header') {
        return (
            <div className="flex min-h-screen w-full flex-col">{children}</div>
        );
    }

    return (
        <SidebarProvider
            defaultOpen={isOpen}
            className="h-svh min-h-0 bg-muted [--header-height:48px]"
            style={LAYOUT_SIZES}
        >
            <AppModuleProvider>{children}</AppModuleProvider>
        </SidebarProvider>
    );
}
