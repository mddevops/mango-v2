import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    ClipboardList,
    Globe,
    History,
    LayoutGrid,
    LifeBuoy,
    PlugZap,
    ShieldCheck,
    Shuffle,
    Tags,
    Users,
    UsersRound,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { AppSwitcher } from '@/components/app-switcher';
import { NavMain } from '@/components/nav-main';
import { Badge } from '@/components/reui/badge';
import { Separator } from '@/components/ui/separator';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarTrigger,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { usePermissions } from '@/hooks/use-permissions';
import { dashboard } from '@/routes';
import { index as auditIndex } from '@/routes/audit';
import { index as connectionsIndex } from '@/routes/connections';
import { index as departmentsIndex } from '@/routes/departments';
import { schedules as distributionSchedules } from '@/routes/distribution';
import { index as ordersIndex } from '@/routes/orders';
import { index as rolesIndex } from '@/routes/roles';
import { index as showroomsIndex } from '@/routes/showrooms';
import { index as referencesIndex } from '@/routes/references';
import { index as sourcesIndex } from '@/routes/sources';
import { index as usersIndex } from '@/routes/users';
import { index as supportIndex } from '@/routes/support';
import type { Abilities, NavItem } from '@/types';

type NavigationItem = NavItem &
    (
        | { permission: string }
        | { ability: keyof Abilities | 'viewReferences' }
    );

export const navigation: NavigationItem[] = [
    {
        title: 'Рабочее место',
        href: dashboard(),
        icon: LayoutGrid,
        permission: 'workspace.view',
    },
    {
        title: 'Заявки',
        href: ordersIndex(),
        icon: ClipboardList,
        permission: 'orders.view',
    },
    {
        title: 'Графики распределения',
        href: distributionSchedules(),
        activePrefix: ['/distribution'],
        icon: Shuffle,
        ability: 'viewSchedules',
    },
    {
        title: 'Автосалоны',
        href: showroomsIndex(),
        icon: Building2,
        permission: 'showrooms.view',
    },
    {
        title: 'Отделы',
        href: departmentsIndex(),
        icon: UsersRound,
        permission: 'departments.view',
    },
    {
        title: 'Сотрудники',
        href: usersIndex(),
        icon: Users,
        permission: 'users.view',
    },
    {
        title: 'Источники',
        href: sourcesIndex(),
        icon: Globe,
        permission: 'sources.view',
    },
    {
        title: 'Справочники',
        href: referencesIndex(),
        activePrefix: ['/references', '/source-references/'],
        icon: Tags,
        ability: 'viewReferences',
    },
    {
        title: 'Подключения',
        href: connectionsIndex(),
        icon: PlugZap,
        ability: 'manageConnections',
    },
    {
        title: 'Роли и права',
        href: rolesIndex(),
        icon: ShieldCheck,
        permission: 'roles.manage',
    },
    {
        title: 'Аудит',
        href: auditIndex(),
        icon: History,
        permission: 'audit.view',
    },
];

export function AppSidebar() {
    const { can, allows } = usePermissions();
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const supportUnread = usePage().props.supportUnread ?? 0;
    const items = navigation.filter((item) => {
        if (!('ability' in item)) {
            return can(item.permission);
        }

        switch (item.ability) {
            case 'viewReferences':
                return (
                    allows('viewOrderReferences') ||
                    allows('viewSourceReferences')
                );
            default:
                return allows(item.ability);
        }
    });

    return (
        <Sidebar
            collapsible="icon"
            variant="floating"
            className="[&_[data-slot=sidebar-inner]]:bg-background"
        >
            <SidebarHeader className="flex-row items-center gap-1 group-data-[collapsible=icon]:flex-col">
                <SidebarMenu className="min-w-0 flex-1 group-data-[collapsible=icon]:flex-none">
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <SidebarTrigger className="shrink-0 text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground dark:hover:bg-sidebar-accent [&_svg]:transition-transform [&_svg]:duration-200 group-data-[collapsible=icon]:[&_svg]:rotate-180" />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
                <SidebarGroup className="mt-auto">
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                size="sm"
                                tooltip={{ children: 'Поддержка' }}
                                className="[&_svg]:size-3.5"
                                isActive={isCurrentOrParentUrl(supportIndex())}
                                asChild
                            >
                                <Link href={supportIndex()} prefetch>
                                    <LifeBuoy />
                                    <span>Поддержка</span>
                                </Link>
                            </SidebarMenuButton>
                            {supportUnread > 0 && (
                                <SidebarMenuBadge className="peer-data-[size=sm]/menu-button:top-1.5">
                                    <Badge variant="success" size="sm">
                                        {supportUnread > 99 ? '99+' : supportUnread}
                                    </Badge>
                                </SidebarMenuBadge>
                            )}
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroup>
            </SidebarContent>

            <SidebarFooter>
                <div className="px-1">
                    <Separator />
                </div>
                <AppSwitcher />
            </SidebarFooter>
        </Sidebar>
    );
}
