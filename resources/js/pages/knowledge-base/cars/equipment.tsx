import { Head, Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
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
import { cn } from '@/lib/utils';
import type { CatalogEquipmentPage } from '@/types/knowledge-base';

export default function CatalogEquipment(props: CatalogEquipmentPage) {
    const { modification, modifications, equipment, equipments, sections } =
        props;

    return (
        <>
            <Head
                title={`Комплектация ${equipment.name} ${seriesTitle(props)}`}
            />
            <PageBody>
                <SeriesHeader context={props} />

                <ModificationNav
                    current={modification.href}
                    modifications={modifications}
                    specificationsHref={modification.href}
                    equipmentHref={
                        equipments.find((item) => item.active)?.href ?? null
                    }
                    active="equipment"
                />

                <div className="grid items-start gap-4 lg:grid-cols-[18rem_1fr]">
                    <Frame>
                        <FrameHeader>
                            <FrameTitle>Комплектации</FrameTitle>
                        </FrameHeader>
                        <FramePanel className="flex flex-col gap-1 p-1.5">
                            {equipments.map((item) => (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    preserveScroll
                                    aria-current={item.active ? 'page' : undefined}
                                    className={cn(
                                        'flex flex-col gap-0.5 rounded-lg px-2.5 py-2 transition-colors hover:bg-muted',
                                        item.active && 'bg-muted',
                                    )}
                                >
                                    <span className="text-sm font-medium">
                                        {item.name}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {item.details}
                                    </span>
                                </Link>
                            ))}
                        </FramePanel>
                    </Frame>

                    <Frame>
                        <FrameHeader className="flex-row flex-wrap items-baseline gap-x-2">
                            <FrameTitle>Комплектация {equipment.name}</FrameTitle>
                            <span className="text-xs text-muted-foreground">
                                {modification.name}
                            </span>
                        </FrameHeader>
                        <FramePanel>
                            {sections.length === 0 ? (
                                <CatalogEmpty
                                    title="Опций пока нет"
                                    description={`Для комплектации ${equipment.name} в каталоге ещё нет списка опций.`}
                                />
                            ) : (
                                <div className="columns-1 gap-8 md:columns-2 xl:columns-3">
                                    {sections.map((section) => (
                                        <section
                                            key={section.name}
                                            className="mb-6 flex break-inside-avoid flex-col gap-2"
                                        >
                                            <h3 className="text-sm font-semibold tracking-tight">
                                                {section.name}
                                            </h3>
                                            <ul className="flex flex-col gap-1.5">
                                                {section.items.map((option) => (
                                                    <li
                                                        key={option}
                                                        className="flex items-start gap-2 text-sm"
                                                    >
                                                        <Check className="mt-0.5 size-4 shrink-0 text-primary" />
                                                        {option}
                                                    </li>
                                                ))}
                                            </ul>
                                        </section>
                                    ))}
                                </div>
                            )}
                        </FramePanel>
                    </Frame>
                </div>
            </PageBody>
        </>
    );
}

CatalogEquipment.layout = (props: CatalogEquipmentPage) => ({
    breadcrumbs: catalogBreadcrumbs(
        props.mark,
        props.model,
        {
            name: `${props.series.generation.name} ${props.series.name}`,
            href: props.series.href,
        },
        {
            name: `Комплектация ${props.equipment.name}`,
            href:
                props.equipments.find((item) => item.active)?.href ??
                props.series.href,
        },
    ),
});
