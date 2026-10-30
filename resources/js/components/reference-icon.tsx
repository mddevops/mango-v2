import { DynamicIcon, iconNames } from 'lucide-react/dynamic';
import type { IconName } from 'lucide-react/dynamic';
import { cn } from '@/lib/utils';
import type { IconColor } from '@/types';

const known = new Set<string>(iconNames);

export function isIconName(name: string | null | undefined): name is IconName {
    return typeof name === 'string' && known.has(name);
}

/** The palette of `App\Enums\IconColor`: a tinted square, the glyph and the picker swatch. */
export const ICON_COLORS: Record<
    IconColor,
    { label: string; tile: string; swatch: string }
> = {
    gray: {
        label: 'Серый',
        tile: 'bg-zinc-100 text-zinc-600 dark:bg-zinc-500/15 dark:text-zinc-300',
        swatch: 'bg-zinc-400',
    },
    blue: {
        label: 'Синий',
        tile: 'bg-blue-100 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
        swatch: 'bg-blue-500',
    },
    sky: {
        label: 'Голубой',
        tile: 'bg-sky-100 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400',
        swatch: 'bg-sky-500',
    },
    teal: {
        label: 'Бирюзовый',
        tile: 'bg-teal-100 text-teal-600 dark:bg-teal-500/15 dark:text-teal-400',
        swatch: 'bg-teal-500',
    },
    green: {
        label: 'Зелёный',
        tile: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
        swatch: 'bg-emerald-500',
    },
    amber: {
        label: 'Жёлтый',
        tile: 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
        swatch: 'bg-amber-500',
    },
    orange: {
        label: 'Оранжевый',
        tile: 'bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-400',
        swatch: 'bg-orange-500',
    },
    red: {
        label: 'Красный',
        tile: 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
        swatch: 'bg-red-500',
    },
    pink: {
        label: 'Розовый',
        tile: 'bg-pink-100 text-pink-600 dark:bg-pink-500/15 dark:text-pink-400',
        swatch: 'bg-pink-500',
    },
    violet: {
        label: 'Фиолетовый',
        tile: 'bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400',
        swatch: 'bg-violet-500',
    },
};

/** A Lucide icon chosen in a reference, by name; unknown names render nothing. */
export function ReferenceIcon({
    name,
    color,
    className,
}: {
    name: string | null | undefined;
    color?: IconColor | null;
    className?: string;
}) {
    if (!isIconName(name)) {
        return null;
    }

    return (
        <div
            className={cn(
                'flex size-8 shrink-0 items-center justify-center rounded-lg [&_svg]:size-4',
                ICON_COLORS[color ?? 'gray'].tile,
                className,
            )}
        >
            <DynamicIcon name={name} aria-hidden="true" />
        </div>
    );
}

/** The colour swatches of the palette, as a single-choice group. */
export function IconColorPicker({
    value,
    onChange,
    disabled,
}: {
    value: IconColor;
    onChange: (color: IconColor) => void;
    disabled?: boolean;
}) {
    return (
        <div role="radiogroup" className="flex flex-wrap gap-1.5">
            {(Object.keys(ICON_COLORS) as IconColor[]).map((color) => (
                <button
                    key={color}
                    type="button"
                    role="radio"
                    aria-checked={value === color}
                    aria-label={ICON_COLORS[color].label}
                    title={ICON_COLORS[color].label}
                    disabled={disabled}
                    onClick={() => onChange(color)}
                    className={cn(
                        'flex size-7 items-center justify-center rounded-full border border-transparent transition-colors hover:border-border focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50',
                        value === color && 'border-foreground/40',
                    )}
                >
                    <span
                        className={cn(
                            'size-4 rounded-full',
                            ICON_COLORS[color].swatch,
                        )}
                    />
                </button>
            ))}
        </div>
    );
}
