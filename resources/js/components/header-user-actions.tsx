import { usePage } from '@inertiajs/react';
import { MoonStar, Sun } from 'lucide-react';
import { HeaderClock } from '@/components/header-clock';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useAppearance } from '@/hooks/use-appearance';
import { useInitials } from '@/hooks/use-initials';

/** The right side of the page header: the theme switch and the profile menu. */
export function HeaderUserActions() {
    const { auth } = usePage().props;
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const getInitials = useInitials();
    const isDark = resolvedAppearance === 'dark';

    return (
        <div className="ml-auto flex items-center gap-1.5">
            <HeaderClock />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="relative"
                onClick={() => updateAppearance(isDark ? 'light' : 'dark')}
                title={isDark ? 'Светлая тема' : 'Тёмная тема'}
            >
                <MoonStar className="scale-100 transition-transform duration-200 dark:scale-0" />
                <Sun className="absolute scale-0 transition-transform duration-200 dark:scale-100" />
                <span className="sr-only">
                    {isDark ? 'Включить светлую тему' : 'Включить тёмную тему'}
                </span>
            </Button>

            {auth.user && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="relative rounded-full hover:bg-transparent dark:hover:bg-transparent"
                            aria-label={`Профиль: ${auth.user.name}`}
                        >
                            <Avatar className="size-8 overflow-hidden rounded-full">
                                <AvatarImage
                                    src={auth.user.avatar}
                                    alt={auth.user.name}
                                />
                                <AvatarFallback className="rounded-full bg-neutral-200 text-xs text-black dark:bg-neutral-700 dark:text-white">
                                    {getInitials(auth.user.name)}
                                </AvatarFallback>
                            </Avatar>
                            <span
                                className="absolute right-0 bottom-0 block size-2 rounded-full bg-success ring-2 ring-background"
                                aria-hidden="true"
                            />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent className="min-w-56" align="end">
                        <UserMenuContent user={auth.user} />
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </div>
    );
}
