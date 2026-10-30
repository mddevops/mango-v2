import { Breadcrumbs } from '@/components/breadcrumbs';
import { HeaderUserActions } from '@/components/header-user-actions';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="sticky top-0 z-10 flex h-12 shrink-0 items-center justify-between gap-4 border-b border-border/60 bg-background/95 px-4 backdrop-blur-sm">
            <SidebarTrigger className="-ml-1 md:hidden" />
            <Separator
                orientation="vertical"
                className="mr-2 data-vertical:h-4 data-vertical:self-auto md:hidden"
            />
            <Breadcrumbs breadcrumbs={breadcrumbs} />
            <HeaderUserActions />
        </header>
    );
}
