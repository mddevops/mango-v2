import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({ items }: { items: NavItem[] }) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <SidebarGroup>
            <SidebarMenu className="gap-2">
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={[item.activePrefix ?? item.href]
                                .flat()
                                .some((prefix) =>
                                    isCurrentOrParentUrl(prefix),
                                )}
                            tooltip={{ children: item.title }}
                        >
                            <Link
                                href={item.href}
                                prefetch
                                className="font-medium"
                            >
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                        {item.items?.length ? (
                            <SidebarMenuSub className="ml-0 border-l-0 px-1.5">
                                {item.items.map((subItem) => (
                                    <SidebarMenuSubItem key={subItem.title}>
                                        <SidebarMenuSubButton
                                            asChild
                                            isActive={isCurrentUrl(
                                                subItem.href,
                                            )}
                                        >
                                            <Link href={subItem.href} prefetch>
                                                {subItem.title}
                                            </Link>
                                        </SidebarMenuSubButton>
                                    </SidebarMenuSubItem>
                                ))}
                            </SidebarMenuSub>
                        ) : null}
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
