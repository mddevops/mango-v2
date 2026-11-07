import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Building2,
    ChevronUp,
    ClipboardList,
    Globe,
    History,
    LayoutGrid,
    LifeBuoy,
    Mail,
    MessagesSquare,
    PanelLeft,
    Phone,
    PlugZap,
    ShieldCheck,
    Shuffle,
    Tags,
    Users,
    UsersRound,
    Warehouse,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { createContext, useContext, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { RailClock } from '@/components/header-clock';
import { Badge } from '@/components/reui/badge';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useSidebar } from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { UserMenuContent } from '@/components/user-menu-content';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useInitials } from '@/hooks/use-initials';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as auditIndex } from '@/routes/audit';
import { index as connectionsIndex } from '@/routes/connections';
import { index as departmentsIndex } from '@/routes/departments';
import { schedules as distributionSchedules } from '@/routes/distribution';
import { index as knowledgeBaseIndex } from '@/routes/knowledge-base';
import { index as ordersIndex } from '@/routes/orders';
import { index as referencesIndex } from '@/routes/references';
import { index as rolesIndex } from '@/routes/roles';
import { index as showroomsIndex } from '@/routes/showrooms';
import { index as sourcesIndex } from '@/routes/sources';
import { index as supportIndex } from '@/routes/support';
import { index as usersIndex } from '@/routes/users';
import type { Abilities, NavItem } from '@/types';

type NavGroupKey = 'work' | 'company' | 'settings';

type NavigationItem = NavItem & { group: NavGroupKey } & (
        { permission: string } | { ability: keyof Abilities | 'viewReferences' }
    );

export const navigation: NavigationItem[] = [
    {
        title: 'Рабочее место',
        href: dashboard(),
        icon: LayoutGrid,
        group: 'work',
        permission: 'workspace.view',
    },
    {
        title: 'Заявки',
        href: ordersIndex(),
        icon: ClipboardList,
        group: 'work',
        permission: 'orders.view',
    },
    {
        title: 'Графики распределения',
        href: distributionSchedules(),
        activePrefix: ['/distribution'],
        icon: Shuffle,
        group: 'work',
        ability: 'viewSchedules',
    },
    {
        title: 'Автосалоны',
        href: showroomsIndex(),
        icon: Building2,
        group: 'company',
        permission: 'showrooms.view',
    },
    {
        title: 'Отделы',
        href: departmentsIndex(),
        icon: UsersRound,
        group: 'company',
        permission: 'departments.view',
    },
    {
        title: 'Сотрудники',
        href: usersIndex(),
        icon: Users,
        group: 'company',
        permission: 'users.view',
    },
    {
        title: 'Источники',
        href: sourcesIndex(),
        icon: Globe,
        group: 'settings',
        permission: 'sources.view',
    },
    {
        title: 'Справочники',
        href: referencesIndex(),
        activePrefix: ['/references', '/source-references/'],
        icon: Tags,
        group: 'settings',
        ability: 'viewReferences',
    },
    {
        title: 'Подключения',
        href: connectionsIndex(),
        icon: PlugZap,
        group: 'settings',
        ability: 'manageConnections',
    },
    {
        title: 'Роли и права',
        href: rolesIndex(),
        icon: ShieldCheck,
        group: 'settings',
        permission: 'roles.manage',
    },
    {
        title: 'Аудит',
        href: auditIndex(),
        icon: History,
        group: 'settings',
        permission: 'audit.view',
    },
];

/** The first group goes without a title, as the main links of the module. */
const NAV_GROUPS: { key: NavGroupKey; title: string | null }[] = [
    { key: 'work', title: null },
    { key: 'company', title: 'Компания' },
    { key: 'settings', title: 'Настройки' },
];

type ModuleKey = 'crm' | 'chat' | 'warehouse' | 'mail';

type AppModule = {
    key: ModuleKey;
    title: string;
    icon: LucideIcon;
    color: string;
    available: boolean;
};

/** Only the CRM exists so far; the other modules are listed as coming. */
const MODULES: AppModule[] = [
    {
        key: 'crm',
        title: 'CRM',
        icon: Phone,
        color: 'bg-emerald-600',
        available: true,
    },
    {
        key: 'chat',
        title: 'Чат',
        icon: MessagesSquare,
        color: 'bg-sky-500',
        available: false,
    },
    {
        key: 'warehouse',
        title: 'Склад',
        icon: Warehouse,
        color: 'bg-zinc-800',
        available: false,
    },
    {
        key: 'mail',
        title: 'Почта',
        icon: Mail,
        color: 'bg-amber-500',
        available: false,
    },
];

const ModuleContext = createContext<{
    module: AppModule;
    setModule: (key: ModuleKey) => void;
} | null>(null);

export function AppModuleProvider({ children }: { children: ReactNode }) {
    const [key, setKey] = useState<ModuleKey>('crm');
    const { open, setOpen, isMobile } = useSidebar();
    const module = MODULES.find((item) => item.key === key) ?? MODULES[0];

    const setModule = (next: ModuleKey) => {
        setKey(next);

        if (!isMobile && !open) {
            setOpen(true);
        }
    };

    return (
        <ModuleContext.Provider value={{ module, setModule }}>
            {children}
        </ModuleContext.Provider>
    );
}

function useAppModule() {
    const context = useContext(ModuleContext);

    if (!context) {
        throw new Error('useAppModule must be used within AppModuleProvider.');
    }

    return context;
}

function useVisibleNavigation() {
    const { can, allows } = usePermissions();

    return navigation.filter((item) => {
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
}

const RAIL_BUTTON =
    'relative flex size-9 items-center justify-center rounded-[8px] text-zinc-400 transition-colors outline-none hover:bg-zinc-800 hover:text-white focus-visible:ring-2 focus-visible:ring-zinc-600 [&_svg]:size-4 [&_svg]:shrink-0';

function RailTooltip({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>{children}</TooltipTrigger>
            <TooltipContent side="right">{label}</TooltipContent>
        </Tooltip>
    );
}

function RailLink({
    label,
    href,
    onNavigate,
    children,
}: {
    label: string;
    href: NavItem['href'];
    onNavigate?: () => void;
    children: ReactNode;
}) {
    return (
        <RailTooltip label={label}>
            <Link
                href={href}
                prefetch
                aria-label={label}
                onClick={onNavigate}
                className={RAIL_BUTTON}
            >
                {children}
            </Link>
        </RailTooltip>
    );
}

function RailProfile() {
    const { auth } = usePage().props;
    const getInitials = useInitials();

    if (!auth.user) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="relative mx-auto size-9 rounded-full hover:bg-zinc-800 dark:hover:bg-zinc-800"
                    aria-label={`Профиль: ${auth.user.name}`}
                >
                    <Avatar className="size-7 rounded-full">
                        <AvatarImage
                            src={auth.user.avatar}
                            alt={auth.user.name}
                        />
                        <AvatarFallback className="bg-zinc-700 text-xs text-white">
                            {getInitials(auth.user.name)}
                        </AvatarFallback>
                    </Avatar>
                    <span
                        aria-hidden="true"
                        className="absolute top-1 right-1 size-2 rounded-full border border-black bg-emerald-500"
                    />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                className="min-w-56"
                side="right"
                align="end"
                sideOffset={8}
            >
                <UserMenuContent user={auth.user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** The black column: the logo, the modules, help, the clock and the profile. */
function ModuleRail({ onNavigate }: { onNavigate?: () => void }) {
    const { module, setModule } = useAppModule();
    const supportUnread = usePage().props.supportUnread ?? 0;

    return (
        <div className="my-2.5 ms-2.5 me-2.5 flex w-14 shrink-0 flex-col items-center gap-3 rounded-[18px] bg-black py-3 text-zinc-400">
            <div className="flex items-center justify-center pt-0.5">
                <Link
                    href={dashboard()}
                    prefetch
                    aria-label="Рабочее место"
                    onClick={onNavigate}
                    className="flex size-7 items-center justify-center rounded-[7px] bg-white text-black"
                >
                    <AppLogoIcon className="size-4 fill-current" />
                </Link>
            </div>
            <nav
                aria-label="Модули"
                className="flex shrink-0 flex-col items-center gap-1.5 pt-2"
            >
                {MODULES.map((item) => (
                    <RailTooltip
                        key={item.key}
                        label={
                            item.available
                                ? item.title
                                : `${item.title} — скоро`
                        }
                    >
                        <button
                            type="button"
                            onClick={() => setModule(item.key)}
                            aria-label={item.title}
                            aria-pressed={module.key === item.key}
                            className={cn(
                                RAIL_BUTTON,
                                module.key === item.key &&
                                    'bg-zinc-800 text-white',
                            )}
                        >
                            <item.icon />
                        </button>
                    </RailTooltip>
                ))}
            </nav>
            <div className="flex min-h-0 flex-1 items-center justify-center">
                <RailClock />
            </div>
            <div className="flex flex-col items-center gap-1 pb-1">
                <RailLink
                    label="База знаний"
                    href={knowledgeBaseIndex()}
                    onNavigate={onNavigate}
                >
                    <BookOpen />
                </RailLink>
                <RailLink
                    label={
                        supportUnread > 0
                            ? `Поддержка: ${supportUnread} непрочитанных`
                            : 'Поддержка'
                    }
                    href={supportIndex()}
                    onNavigate={onNavigate}
                >
                    <LifeBuoy />
                    {supportUnread > 0 && (
                        <span
                            className="absolute top-1.5 right-1.5 size-2 rounded-full border border-black bg-emerald-500"
                            aria-hidden="true"
                        />
                    )}
                </RailLink>
                <RailProfile />
            </div>
        </div>
    );
}

const NAV_ITEM =
    'h-8 w-full justify-start gap-2 rounded-[8px] px-2 text-sm font-normal text-foreground hover:bg-background/70 dark:hover:bg-muted/70';
const NAV_ITEM_ACTIVE =
    'bg-background shadow-xs hover:bg-background dark:bg-muted dark:hover:bg-muted';

function NavLinks({
    items,
    onNavigate,
}: {
    items: NavigationItem[];
    onNavigate?: () => void;
}) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const isActive = (item: NavigationItem) =>
        [item.activePrefix ?? item.href]
            .flat()
            .some((prefix) => isCurrentOrParentUrl(prefix));

    return (
        <div className="flex flex-col gap-0.5">
            {items.map((item) => (
                <Button
                    key={item.title}
                    variant="ghost"
                    size="sm"
                    className={cn(NAV_ITEM, isActive(item) && NAV_ITEM_ACTIVE)}
                    asChild
                >
                    <Link
                        href={item.href}
                        prefetch
                        onClick={onNavigate}
                        aria-current={isActive(item) ? 'page' : undefined}
                    >
                        {item.icon && (
                            <span className="size-4 shrink-0 text-muted-foreground opacity-80 [&_svg]:size-4">
                                <item.icon />
                            </span>
                        )}
                        <span className="min-w-0 truncate">{item.title}</span>
                    </Link>
                </Button>
            ))}
        </div>
    );
}

function NavSection({
    title,
    items,
    onNavigate,
}: {
    title: string;
    items: NavigationItem[];
    onNavigate?: () => void;
}) {
    const [open, setOpen] = useState(true);

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="flex flex-col gap-1"
        >
            <CollapsibleTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-6 w-full justify-start px-2 text-xs font-normal text-muted-foreground hover:bg-transparent hover:text-foreground dark:hover:bg-transparent"
                >
                    <span>{title}</span>
                    <ChevronUp
                        className={cn(
                            'ml-auto size-3.5 opacity-70 transition-transform duration-200',
                            !open && 'rotate-180',
                        )}
                    />
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <NavLinks items={items} onNavigate={onNavigate} />
            </CollapsibleContent>
        </Collapsible>
    );
}

function ModuleBadge({ module }: { module: AppModule }) {
    return (
        <span
            className={cn(
                'flex size-8 shrink-0 items-center justify-center rounded-md text-white [&_svg]:size-4',
                module.color,
            )}
            aria-hidden="true"
        >
            <module.icon />
        </span>
    );
}

function ComingSoon({ module }: { module: AppModule }) {
    return (
        <div className="flex flex-col items-center gap-3 px-5 py-10 text-center">
            <ModuleBadge module={module} />
            <div className="flex flex-col gap-1">
                <p className="text-sm font-medium">
                    Модуль «{module.title}» в разработке
                </p>
                <p className="text-xs text-muted-foreground">
                    Разделы появятся здесь после запуска модуля.
                </p>
            </div>
            <Badge variant="secondary" size="sm">
                Скоро
            </Badge>
        </div>
    );
}

/** The links of the chosen module. */
function SectionNav({ onNavigate }: { onNavigate?: () => void }) {
    const { module } = useAppModule();
    const items = useVisibleNavigation();

    if (!module.available) {
        return <ComingSoon module={module} />;
    }

    return (
        <nav className="flex flex-col gap-2.5 px-2 pb-3" aria-label="Разделы">
            {NAV_GROUPS.map((group) => {
                const groupItems = items.filter(
                    (item) => item.group === group.key,
                );

                if (groupItems.length === 0) {
                    return null;
                }

                return group.title === null ? (
                    <NavLinks
                        key={group.key}
                        items={groupItems}
                        onNavigate={onNavigate}
                    />
                ) : (
                    <NavSection
                        key={group.key}
                        title={group.title}
                        items={groupItems}
                        onNavigate={onNavigate}
                    />
                );
            })}
        </nav>
    );
}

/** Set on the shell and again on the mobile sheet, which renders outside it. */
export const LAYOUT_SIZES = {
    '--sidebar-width': '76px',
    '--sidebar-width-inner': '240px',
    '--toolbar-height': '48px',
} as CSSProperties;

const HIDDEN_SCROLLBAR =
    'min-h-0 grow overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden';

/** Hides or shows the section panel (on phones, the whole menu). */
export function MenuToggle({ className }: { className?: string }) {
    const { open, isMobile, openMobile, toggleSidebar } = useSidebar();
    const label = (isMobile ? openMobile : open)
        ? 'Скрыть меню'
        : 'Показать меню';

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            className={cn('shrink-0', className)}
            onClick={toggleSidebar}
            aria-label={label}
            title={label}
        >
            <PanelLeft />
        </Button>
    );
}

/** The module links; the top padding puts the first one on the logo line. */
function SectionPanel({ onNavigate }: { onNavigate?: () => void }) {
    return (
        <div className={cn(HIDDEN_SCROLLBAR, 'pt-[22px]')}>
            <SectionNav onNavigate={onNavigate} />
        </div>
    );
}

/** The black column and the section panel, both on the shell background. */
export function AppSidebar() {
    const { open, isMobile, openMobile, setOpenMobile } = useSidebar();
    const close = () => setOpenMobile(false);

    if (isMobile) {
        return (
            <Sheet open={openMobile} onOpenChange={setOpenMobile}>
                <SheetContent
                    side="left"
                    className="w-auto gap-0 bg-muted p-0 sm:max-w-none [&>button]:hidden"
                    style={LAYOUT_SIZES}
                >
                    <SheetHeader className="sr-only">
                        <SheetTitle>Навигация</SheetTitle>
                        <SheetDescription>
                            Модули и разделы приложения.
                        </SheetDescription>
                    </SheetHeader>
                    <div className="flex h-full">
                        <ModuleRail onNavigate={close} />
                        <div className="flex w-(--sidebar-width-inner) flex-col overflow-hidden">
                            <SectionPanel onNavigate={close} />
                        </div>
                    </div>
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <div
            data-state={open ? 'expanded' : 'collapsed'}
            className="flex h-svh shrink-0"
        >
            <ModuleRail />
            <aside
                aria-label="Разделы"
                inert={!open}
                className={cn(
                    'flex shrink-0 overflow-hidden transition-[width] duration-200 ease-linear motion-reduce:transition-none',
                    open ? 'w-(--sidebar-width-inner)' : 'w-0',
                )}
            >
                <div className="flex w-(--sidebar-width-inner) shrink-0 flex-col overflow-hidden">
                    <SectionPanel />
                </div>
            </aside>
        </div>
    );
}
