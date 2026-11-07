import { Link } from '@inertiajs/react';
import { Car, Search } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { cn } from '@/lib/utils';
import {
    index as knowledgeBaseIndex,
    marks as marksIndex,
} from '@/routes/knowledge-base';
import type { BreadcrumbItem } from '@/types';
import type { CatalogLink, CatalogSeriesContext } from '@/types/knowledge-base';

/** Empty catalog state: a stack of blank record cards above the title. */
export function CatalogEmpty({
    title,
    description,
    className,
}: {
    title: string;
    description: string;
    className?: string;
}) {
    return (
        <Empty className={cn('py-16', className)}>
            <EmptyHeader className="max-w-[27rem] gap-4">
                <EmptyMedia className="mb-0">
                    <div aria-hidden="true" className="relative isolate h-24 w-52">
                        <div className="absolute inset-x-6 top-0 h-6 rounded-t-lg border border-border/50 bg-muted/60 dark:bg-muted/30" />
                        <div className="absolute inset-x-3 top-3 h-6 rounded-t-lg border border-border/60 bg-muted/80 dark:bg-muted/50" />
                        <div className="absolute inset-x-0 top-6 flex h-16 items-center gap-3 rounded-lg border border-border bg-background px-4 shadow-sm">
                            <div className="size-8 shrink-0 rounded bg-muted" />
                            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                                <div className="h-2.5 w-3/4 rounded bg-muted" />
                                <div className="h-2 w-1/2 rounded bg-muted/60" />
                            </div>
                        </div>
                        <div className="pointer-events-none absolute inset-x-0 bottom-0 h-8 bg-linear-to-b from-background/0 via-background/60 to-background" />
                    </div>
                </EmptyMedia>
                <div className="flex flex-col items-center gap-2">
                    <EmptyTitle className="text-2xl font-semibold">
                        {title}
                    </EmptyTitle>
                    <EmptyDescription className="max-w-[24rem]">
                        {description}
                    </EmptyDescription>
                </div>
            </EmptyHeader>
        </Empty>
    );
}

/** «1993 – 2001», «2022 – н.в.» or, in the generation list, «c 2022 года». */
export function formatYears(
    from: number | null,
    to: number | null,
    current: 'present' | 'since' = 'present',
): string {
    if (from === null) {
        return to === null ? '' : `до ${to}`;
    }

    if (to === null) {
        return current === 'since' ? `c ${from} года` : `${from} – н.в.`;
    }

    return from === to ? String(from) : `${from} – ${to}`;
}

export function matchesSearch(search: string, ...values: (string | null)[]) {
    const needle = search.trim().toLocaleLowerCase('ru');

    return (
        needle === '' ||
        values.some((value) =>
            value?.toLocaleLowerCase('ru').includes(needle),
        )
    );
}

export function CatalogSearch({
    value,
    onChange,
    placeholder,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder: string;
}) {
    return (
        <InputGroup className="w-full sm:w-72">
            <InputGroupAddon>
                <Search />
            </InputGroupAddon>
            <InputGroupInput
                type="search"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
                aria-label={placeholder}
                autoFocus
            />
        </InputGroup>
    );
}

/** The light logo, or the dark one in dark mode; a missing dark file falls back to the light one. */
export function MarkLogo({
    name,
    logo,
    logoDark,
    className,
}: {
    name: string;
    logo: string | null;
    logoDark: string | null;
    className?: string;
}) {
    const [lightFailed, setLightFailed] = useState(false);
    const [darkFailed, setDarkFailed] = useState(false);
    const light = lightFailed ? null : logo;
    const dark = darkFailed || !logoDark ? light : logoDark;

    if (!light && !dark) {
        return (
            <span
                className={cn(
                    'text-lg font-semibold tracking-tight text-muted-foreground',
                    className,
                )}
                aria-hidden="true"
            >
                {name.slice(0, 2).toUpperCase()}
            </span>
        );
    }

    return (
        <>
            {light && (
                <img
                    src={light}
                    alt={name}
                    loading="lazy"
                    decoding="async"
                    onError={() => setLightFailed(true)}
                    className={cn('object-contain dark:hidden', className)}
                />
            )}
            {dark && (
                <img
                    src={dark}
                    alt={name}
                    loading="lazy"
                    decoding="async"
                    onError={() =>
                        dark === logoDark
                            ? setDarkFailed(true)
                            : setLightFailed(true)
                    }
                    className={cn('hidden object-contain dark:block', className)}
                />
            )}
        </>
    );
}

export function CatalogPhoto({
    src,
    alt,
    className,
}: {
    src: string | null;
    alt: string;
    className?: string;
}) {
    const [failed, setFailed] = useState<string | null>(null);

    if (!src || failed === src) {
        return (
            <span
                className={cn(
                    'flex size-full items-center justify-center text-muted-foreground/60',
                    className,
                )}
            >
                <Car className="size-8" aria-hidden="true" />
                <span className="sr-only">{alt}</span>
            </span>
        );
    }

    return (
        <img
            src={src}
            alt={alt}
            loading="lazy"
            decoding="async"
            onError={() => setFailed(src)}
            className={cn('size-full object-cover', className)}
        />
    );
}

export function catalogBreadcrumbs(...trail: CatalogLink[]): BreadcrumbItem[] {
    return [
        { title: 'База знаний', href: knowledgeBaseIndex() },
        { title: 'Каталог автомобилей', href: marksIndex() },
        ...trail.map((item) => ({ title: item.name, href: item.href })),
    ];
}

export function seriesTitle(context: CatalogSeriesContext): string {
    return `${context.mark.name} ${context.model.name} ${context.series.generation.name}`;
}

/** Title, body and years of the series, its photo and the switch between bodies of the generation. */
export function SeriesHeader({ context }: { context: CatalogSeriesContext }) {
    const { series, siblings } = context;
    const years = formatYears(
        series.generation.year_from,
        series.generation.year_to,
    ).replace(' – ', ' — ');

    return (
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
            <div className="aspect-[4/3] w-full shrink-0 overflow-hidden rounded-xl bg-muted sm:w-56">
                <CatalogPhoto
                    src={series.image}
                    alt={`${seriesTitle(context)} ${series.name}`}
                />
            </div>
            <div className="flex min-w-0 flex-col gap-3">
                <div className="flex flex-col gap-0.5">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {seriesTitle(context)}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {series.name}
                        {years && <span className="tabular-nums"> {years}</span>}
                    </p>
                </div>
                {siblings.length > 1 && (
                    <div
                        className="flex flex-wrap gap-2"
                        role="group"
                        aria-label="Кузов"
                    >
                        {siblings.map((sibling) => (
                            <Button
                                key={sibling.href}
                                variant={sibling.active ? 'secondary' : 'outline'}
                                size="sm"
                                asChild
                            >
                                <Link
                                    href={sibling.href}
                                    aria-current={
                                        sibling.active ? 'page' : undefined
                                    }
                                    preserveScroll
                                >
                                    {sibling.name}
                                </Link>
                            </Button>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
