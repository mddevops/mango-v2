import { Head } from '@inertiajs/react';
import {
    CatalogEmpty,
    SeriesHeader,
    catalogBreadcrumbs,
    seriesTitle,
} from '@/components/knowledge-base/catalog';
import { ModificationNav } from '@/components/knowledge-base/modification-nav';
import { PageBody } from '@/components/organization/page-header';
import {
    Frame,
    FrameHeader,
    FramePanel,
    FrameTitle,
} from '@/components/reui/frame';
import type {
    CatalogFact,
    CatalogSpecificationsPage,
} from '@/types/knowledge-base';

function Facts({ facts }: { facts: CatalogFact[] }) {
    return (
        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
            {facts.map((fact) => (
                <div key={fact.label} className="flex min-w-0 flex-col gap-0.5">
                    <dt className="text-xs text-muted-foreground">
                        {fact.label}
                    </dt>
                    <dd className="truncate text-sm font-medium tabular-nums">
                        {fact.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export default function CatalogSpecifications(props: CatalogSpecificationsPage) {
    const { modification, modifications, summary, dimensions, sections, equipments } =
        props;

    return (
        <>
            <Head
                title={`Характеристики ${seriesTitle(props)} ${modification.name}`}
            />
            <PageBody>
                <SeriesHeader context={props} />

                <ModificationNav
                    current={modification.href}
                    modifications={modifications}
                    specificationsHref={modification.href}
                    equipmentHref={equipments[0]?.href ?? null}
                    active="specifications"
                />

                {(summary.length > 0 || dimensions.length > 0) && (
                    <Frame>
                        <FrameHeader>
                            <FrameTitle>{modification.name}</FrameTitle>
                        </FrameHeader>
                        {summary.length > 0 && (
                            <FramePanel>
                                <Facts facts={summary} />
                            </FramePanel>
                        )}
                        {dimensions.length > 0 && (
                            <FramePanel className="flex flex-col gap-3">
                                <span className="text-xs font-medium text-muted-foreground">
                                    Размеры кузова
                                </span>
                                <Facts facts={dimensions} />
                            </FramePanel>
                        )}
                    </Frame>
                )}

                {sections.length === 0 ? (
                    <CatalogEmpty
                        title="Характеристик пока нет"
                        description={`Для модификации ${modification.name} в каталоге ещё нет технических характеристик.`}
                    />
                ) : (
                    <div className="gap-4 lg:columns-2 *:mb-4 *:break-inside-avoid">
                        {sections.map((section) => (
                            <Frame key={section.name}>
                                <FrameHeader>
                                    <FrameTitle>{section.name}</FrameTitle>
                                </FrameHeader>
                                <FramePanel className="py-1">
                                    <dl className="flex flex-col">
                                        {section.items.map((item) => (
                                            <div
                                                key={item.name}
                                                className="flex items-baseline justify-between gap-4 border-b border-dashed py-2 last:border-b-0"
                                            >
                                                <dt className="text-sm text-muted-foreground">
                                                    {item.name}
                                                </dt>
                                                <dd className="text-right text-sm font-medium">
                                                    {item.value}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </FramePanel>
                            </Frame>
                        ))}
                    </div>
                )}
            </PageBody>
        </>
    );
}

CatalogSpecifications.layout = (props: CatalogSpecificationsPage) => ({
    breadcrumbs: catalogBreadcrumbs(
        props.mark,
        props.model,
        {
            name: `${props.series.generation.name} ${props.series.name}`,
            href: props.series.href,
        },
        { name: 'Характеристики', href: props.modification.href },
    ),
});
