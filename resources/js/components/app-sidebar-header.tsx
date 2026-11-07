import { Link } from '@inertiajs/react';
import { Fragment } from 'react';
import { MenuToggle } from '@/components/app-sidebar';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/** Parents show from `md`; on narrow screens only the page stays. */
function PageBreadcrumb({
    breadcrumbs,
}: {
    breadcrumbs: BreadcrumbItemType[];
}) {
    if (breadcrumbs.length === 0) {
        return null;
    }

    return (
        <Breadcrumb className="min-w-0">
            <BreadcrumbList className="flex-nowrap">
                {breadcrumbs.map((item, index) => {
                    const isLast = index === breadcrumbs.length - 1;

                    return (
                        <Fragment key={index}>
                            {index > 0 && (
                                <BreadcrumbSeparator className="hidden items-center md:flex">
                                    <span className="inline-flex h-0.5 w-2 shrink-0 rounded-full bg-foreground/30" />
                                </BreadcrumbSeparator>
                            )}
                            {isLast ? (
                                <BreadcrumbItem className="min-w-0">
                                    <BreadcrumbPage className="truncate">
                                        {item.title}
                                    </BreadcrumbPage>
                                </BreadcrumbItem>
                            ) : (
                                <BreadcrumbItem className="hidden md:inline-flex">
                                    <BreadcrumbLink asChild>
                                        <Link href={item.href}>
                                            {item.title}
                                        </Link>
                                    </BreadcrumbLink>
                                </BreadcrumbItem>
                            )}
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="flex h-(--header-height) shrink-0 items-center gap-2 border-b px-4">
            <div className="flex min-w-0 items-center gap-2">
                <MenuToggle className="-ml-1" />
                <PageBreadcrumb breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
