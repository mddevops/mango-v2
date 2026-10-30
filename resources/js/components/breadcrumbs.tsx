import { Link } from '@inertiajs/react';
import { LifeBuoy, Settings } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment } from 'react';
import { navigation } from '@/components/app-sidebar';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { toUrl } from '@/lib/utils';
import { index as supportIndex } from '@/routes/support';
import type { BreadcrumbItem as BreadcrumbItemType, NavItem } from '@/types';

type Section = {
    paths: string[];
    icon: LucideIcon;
};

function pathOf(href: NavItem['href'] | string): string {
    return toUrl(href)
        .replace(/^https?:\/\/[^/]+/, '')
        .split(/[?#]/)[0];
}

const SECTIONS: Section[] = [
    ...navigation.flatMap((item) =>
        item.icon
            ? [
                  {
                      paths: [
                          pathOf(item.href),
                          ...[item.activePrefix ?? []].flat(),
                      ],
                      icon: item.icon,
                  },
              ]
            : [],
    ),
    { paths: [pathOf(supportIndex())], icon: LifeBuoy },
    { paths: ['/settings'], icon: Settings },
];

/** The sidebar icon of the section a page belongs to. */
function sectionIcon(href: BreadcrumbItemType['href']): LucideIcon | null {
    const path = pathOf(href);

    const section = SECTIONS.find(({ paths }) =>
        paths.some((candidate) => {
            const base = candidate.replace(/\/+$/, '');

            return base === ''
                ? path === '/'
                : path === base || path.startsWith(`${base}/`);
        }),
    );

    return section?.icon ?? null;
}

/** Section with its icon first, then the inner pages as plain text (ReUI c-breadcrumb-5). */
export function Breadcrumbs({
    breadcrumbs,
}: {
    breadcrumbs: BreadcrumbItemType[];
}) {
    if (breadcrumbs.length === 0) {
        return null;
    }

    const Icon = sectionIcon(breadcrumbs[0].href);

    return (
        <Breadcrumb className="min-w-0">
            <BreadcrumbList className="flex-nowrap">
                {breadcrumbs.map((item, index) => {
                    const isLast = index === breadcrumbs.length - 1;
                    const content = (
                        <>
                            {index === 0 && Icon && (
                                <Icon className="size-4 shrink-0" />
                            )}
                            <span className="truncate">{item.title}</span>
                        </>
                    );

                    return (
                        <Fragment key={index}>
                            {index > 0 && <BreadcrumbSeparator />}
                            <BreadcrumbItem className="min-w-0">
                                {isLast ? (
                                    <BreadcrumbPage className="flex max-w-64 items-center gap-1.5">
                                        {content}
                                    </BreadcrumbPage>
                                ) : (
                                    <BreadcrumbLink
                                        asChild
                                        className="flex items-center gap-1.5"
                                    >
                                        <Link href={item.href}>{content}</Link>
                                    </BreadcrumbLink>
                                )}
                            </BreadcrumbItem>
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}
