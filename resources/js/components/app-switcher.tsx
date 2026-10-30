import { Check, ChevronsUpDown, Phone, Send, Truck } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
type AppOption = {
    key: string;
    title: string;
    icon: LucideIcon;
    logo: string;
    available: boolean;
};

const APPS: AppOption[] = [
    {
        key: 'crm',
        title: 'CRM',
        icon: Phone,
        logo: 'text-emerald-500!',
        available: true,
    },
    {
        key: 'chat',
        title: 'Чат',
        icon: Send,
        logo: 'text-[#229ED9]!',
        available: false,
    },
    {
        key: 'warehouse',
        title: 'Склад',
        icon: Truck,
        logo: 'text-zinc-950! dark:text-zinc-100!',
        available: false,
    },
];

function AppLogo({ app }: { app: AppOption }) {
    return <app.icon className={app.logo} />;
}

/** Only the CRM exists so far; the chat and the warehouse are listed as coming. */
export function AppSwitcher() {
    const { isMobile, state } = useSidebar();
    const current = APPS[0];

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            aria-label="Сменить приложение"
                            tooltip={{ children: current.title }}
                        >
                            <AppLogo app={current} />
                            <span className="truncate font-medium">
                                {current.title}
                            </span>
                            <ChevronsUpDown className="ml-auto size-3.5! opacity-60 group-data-[collapsible=icon]:hidden" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-48"
                        side={isMobile || state === 'expanded' ? 'top' : 'right'}
                        align="start"
                    >
                        {APPS.map((app) => (
                            <DropdownMenuItem
                                key={app.key}
                                disabled={!app.available}
                            >
                                <AppLogo app={app} />
                                <span className="grow">{app.title}</span>
                                {app.key === current.key ? (
                                    <Check className="text-primary" />
                                ) : (
                                    !app.available && (
                                        <span className="text-xs text-muted-foreground">
                                            скоро
                                        </span>
                                    )
                                )}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
