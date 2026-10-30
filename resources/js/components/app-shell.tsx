import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
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
            className={cn(
                'h-svh bg-muted',
                '[&_[data-sidebar=menu-button]:hover]:bg-sidebar-accent/80 [&_[data-sidebar=menu-button]:hover]:text-foreground [&_[data-sidebar=menu-button]:hover]:ring-1 [&_[data-sidebar=menu-button]:hover]:ring-border/60 [&_[data-sidebar=menu-button]:hover]:ring-inset',
                '[&_[data-sidebar=menu-button][data-active=true]]:bg-sidebar-accent [&_[data-sidebar=menu-button][data-active=true]]:text-foreground [&_[data-sidebar=menu-button][data-active=true]]:ring-1 [&_[data-sidebar=menu-button][data-active=true]]:ring-border/60 [&_[data-sidebar=menu-button][data-active=true]]:ring-inset [&_[data-sidebar=menu-button][data-active=true]>svg]:text-primary',
            )}
            style={{ '--sidebar-width': '255px' } as React.CSSProperties}
        >
            {children}
        </SidebarProvider>
    );
}
