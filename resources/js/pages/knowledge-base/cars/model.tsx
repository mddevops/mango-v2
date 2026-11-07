import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    CatalogEmpty,
    CatalogPhoto,
    catalogBreadcrumbs,
    formatYears,
} from '@/components/knowledge-base/catalog';
import { PageBody, PageHeader } from '@/components/organization/page-header';
import {
    Attachment,
    AttachmentContent,
    AttachmentDescription,
    AttachmentMedia,
    AttachmentTitle,
    AttachmentTrigger,
} from '@/components/ui/attachment';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type {
    CatalogGeneration,
    CatalogModelPage,
} from '@/types/knowledge-base';

/** One generation: hovering or focusing a body swaps the photo, as on auto.ru. */
function GenerationCard({
    title,
    generation,
}: {
    title: string;
    generation: CatalogGeneration;
}) {
    const [active, setActive] = useState(0);
    const current = generation.series[active] ?? generation.series.at(0);

    return (
        <Attachment
            orientation="vertical"
            role="listitem"
            className="w-full has-data-[slot=attachment-content]:w-full"
        >
            <AttachmentMedia className="aspect-[4/3]">
                <CatalogPhoto
                    src={current?.image ?? null}
                    alt={`${title} ${generation.name} ${current?.name ?? ''}`}
                />
            </AttachmentMedia>
            <AttachmentContent className="flex flex-col gap-2">
                <div>
                    <AttachmentTitle>{generation.name}</AttachmentTitle>
                    <AttachmentDescription className="tabular-nums">
                        {formatYears(
                            generation.year_from,
                            generation.year_to,
                            'since',
                        )}
                    </AttachmentDescription>
                </div>
                {generation.series.length === 0 && (
                    <span className="text-xs text-muted-foreground">
                        Кузова в каталоге не указаны
                    </span>
                )}
                <div
                    className="relative z-20 flex flex-wrap gap-1.5"
                    role="group"
                    aria-label={`Кузова поколения ${generation.name}`}
                >
                    {generation.series.map((series, index) => (
                        <Button
                            key={series.href}
                            variant="outline"
                            className={cn(
                                index === active && 'bg-muted text-foreground',
                            )}
                            asChild
                        >
                            <Link
                                href={series.href}
                                onMouseEnter={() => setActive(index)}
                                onFocus={() => setActive(index)}
                            >
                                {series.name}
                            </Link>
                        </Button>
                    ))}
                </div>
            </AttachmentContent>
            {current && (
                <AttachmentTrigger asChild>
                    <Link
                        href={current.href}
                        aria-label={`${title} ${generation.name} ${current.name}`}
                    />
                </AttachmentTrigger>
            )}
        </Attachment>
    );
}

export default function CatalogModel({
    mark,
    model,
    generations,
}: CatalogModelPage) {
    const title = `${mark.name} ${model.name}`;
    const bodies = [
        ...new Set(
            generations.flatMap((generation) =>
                generation.series.map((series) => series.name),
            ),
        ),
    ];
    const description = [
        formatYears(model.year_from, model.year_to),
        model.class ? `${model.class}-класс` : null,
        bodies.length > 0 ? bodies.join(', ') : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <>
            <Head title={title} />
            <PageBody>
                <PageHeader title={title} description={description} />

                <section className="flex flex-col gap-3">
                    <h2 className="text-base font-semibold tracking-tight">
                        Поколения модели
                    </h2>
                    {generations.length === 0 ? (
                        <CatalogEmpty
                            title="Поколений пока нет"
                            description={`Для ${title} в каталоге ещё нет поколений и кузовов.`}
                        />
                    ) : (
                        <div
                            className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                            role="list"
                            aria-label={`Поколения ${title}`}
                        >
                            {generations.map((generation) => (
                                <GenerationCard
                                    key={generation.key}
                                    title={title}
                                    generation={generation}
                                />
                            ))}
                        </div>
                    )}
                </section>
            </PageBody>
        </>
    );
}

CatalogModel.layout = ({ mark, model }: CatalogModelPage) => ({
    breadcrumbs: catalogBreadcrumbs(mark, model),
});
