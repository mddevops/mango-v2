import * as React from 'react';
import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import type { AppVariant } from '@/types';

type Props = React.ComponentProps<'main'> & {
    variant?: AppVariant;
};

export function AppContent({
    variant = 'sidebar',
    children,
    className,
    ...props
}: Props) {
    if (variant === 'sidebar') {
        return (
            <SidebarInset
                className={cn(
                    'min-h-0 overflow-hidden md:my-2.5 md:me-2.5 md:rounded-[18px] md:shadow-sm dark:md:border dark:md:border-border/60',
                    className,
                )}
                {...props}
            >
                {children}
            </SidebarInset>
        );
    }

    return (
        <main
            className={cn(
                'mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl',
                className,
            )}
            {...props}
        >
            {children}
        </main>
    );
}
