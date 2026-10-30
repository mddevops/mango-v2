import { Moon, Sun } from 'lucide-react';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

type ThemeToggleProps = {
    className?: string;
};

export function ThemeToggle({ className }: ThemeToggleProps) {
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const isDark = resolvedAppearance === 'dark';

    return (
        <button
            type="button"
            aria-label={
                isDark ? 'Включить светлую тему' : 'Включить тёмную тему'
            }
            className={cn(
                'flex h-8 w-16 cursor-pointer rounded-full p-1 transition-colors duration-300 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                isDark
                    ? 'border border-zinc-800 bg-zinc-950'
                    : 'border border-zinc-200 bg-white',
                className,
            )}
            onClick={() => updateAppearance(isDark ? 'light' : 'dark')}
        >
            <span className="flex w-full items-center justify-between">
                <span
                    className={cn(
                        'flex size-6 items-center justify-center rounded-full transition-transform duration-300',
                        isDark
                            ? 'translate-x-0 bg-zinc-800'
                            : 'translate-x-8 bg-gray-200',
                    )}
                >
                    {isDark ? (
                        <Moon className="size-4 text-white" strokeWidth={1.5} />
                    ) : (
                        <Sun
                            className="size-4 text-gray-700"
                            strokeWidth={1.5}
                        />
                    )}
                </span>
                <span
                    className={cn(
                        'flex size-6 items-center justify-center rounded-full transition-transform duration-300',
                        isDark ? 'bg-transparent' : '-translate-x-8',
                    )}
                >
                    {isDark ? (
                        <Sun className="size-4 text-gray-500" strokeWidth={1.5} />
                    ) : (
                        <Moon className="size-4 text-black" strokeWidth={1.5} />
                    )}
                </span>
            </span>
        </button>
    );
}
