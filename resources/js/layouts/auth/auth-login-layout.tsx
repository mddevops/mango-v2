import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Frame, FramePanel } from '@/components/reui/frame';
import { AnimatedGridPattern } from '@/components/ui/animated-grid-pattern';
import { ThemeToggle } from '@/components/ui/theme-toggle';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

const photo = '/login-bg.webp';
const photoDark = '/login-bg-dark.webp';

export default function AuthLoginLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="relative isolate flex min-h-svh w-full items-center justify-center overflow-hidden bg-background p-6 md:p-10">
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 -z-10 overflow-hidden"
            >
                <AnimatedGridPattern
                    numSquares={44}
                    maxOpacity={0.08}
                    width={40}
                    height={40}
                    duration={3}
                    repeatDelay={1}
                    className="inset-x-[-10%] inset-y-[-8%] h-[116%] w-[120%] [mask-image:radial-gradient(72%_64%_at_50%_48%,black,rgba(0,0,0,0.72),transparent)] fill-gray-400/12 stroke-gray-400/7 dark:fill-gray-500/9 dark:stroke-gray-500/6"
                />
            </div>
            <div className="flex w-full max-w-sm items-center justify-center gap-20 lg:max-w-none">
                <div className="flex w-full max-w-sm flex-col gap-6 lg:h-[85svh] lg:max-h-[880px]">
                    <ThemeToggle className="self-end" />
                    <div className="flex flex-col gap-6 lg:my-auto">
                        <div className="flex flex-col items-center gap-2 text-center">
                            <Link href={home()} className="mb-2">
                                <AppLogoIcon className="size-9 fill-current text-foreground" />
                                <span className="sr-only">{title}</span>
                            </Link>
                            <h1 className="text-2xl font-bold">{title}</h1>
                            <p className="text-balance text-muted-foreground">
                                {description}
                            </p>
                        </div>
                        {children}
                    </div>
                </div>
                <Frame
                    spacing="lg"
                    className="hidden aspect-[2/3] h-[85svh] max-h-[880px] shrink-0 lg:flex"
                >
                    <FramePanel className="p-0">
                        <img
                            src={photo}
                            alt=""
                            className="absolute inset-0 size-full object-cover dark:hidden"
                        />
                        <img
                            src={photoDark}
                            alt=""
                            className="absolute inset-0 hidden size-full object-cover dark:block"
                        />
                    </FramePanel>
                </Frame>
            </div>
        </div>
    );
}
