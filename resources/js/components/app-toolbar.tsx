import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * The optional bar under the page header: tabs on the left, actions on the right.
 * A page renders it as its first child; it stays on top while the page scrolls.
 */
export function AppToolbar({ className, ...props }: ComponentProps<'div'>) {
    return (
        <div
            className={cn(
                'sticky top-0 z-10 flex min-h-(--toolbar-height) shrink-0 items-stretch justify-between gap-2 border-b border-border bg-background px-4',
                className,
            )}
            {...props}
        />
    );
}
