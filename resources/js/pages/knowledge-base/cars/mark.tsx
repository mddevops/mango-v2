import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    CatalogEmpty,
    CatalogPhoto,
    CatalogSearch,
    MarkLogo,
    catalogBreadcrumbs,
    formatYears,
    matchesSearch,
} from '@/components/knowledge-base/catalog';
import { PageBody } from '@/components/organization/page-header';
import {
    Attachment,
    AttachmentContent,
    AttachmentDescription,
    AttachmentMedia,
    AttachmentTitle,
    AttachmentTrigger,
} from '@/components/ui/attachment';
import type { CatalogMarkPage } from '@/types/knowledge-base';

export default function CatalogMark({ mark, models }: CatalogMarkPage) {
    const [search, setSearch] = useState('');
    const visible = useMemo(
        () => models.filter((model) => matchesSearch(search, model.name)),
        [models, search],
    );
    const details = [mark.name_ru, mark.country].filter(Boolean).join(' · ');

    return (
        <>
            <Head title={`${mark.name} — модели`} />
            <PageBody>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <span className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-xl border bg-background">
                            <MarkLogo
                                name={mark.name}
                                logo={mark.logo}
                                logoDark={mark.logo_dark}
                                className="size-full p-2"
                            />
                        </span>
                        <div className="flex flex-col gap-0.5">
                            <h1 className="text-xl font-semibold tracking-tight">
                                {mark.name}
                            </h1>
                            {details && (
                                <p className="text-sm text-muted-foreground">
                                    {details}
                                </p>
                            )}
                        </div>
                    </div>
                    <CatalogSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Поиск модели"
                    />
                </div>

                {visible.length === 0 ? (
                    models.length === 0 ? (
                        <CatalogEmpty
                            title="Моделей пока нет"
                            description={`Для марки ${mark.name} в каталоге ещё нет моделей с кузовами и фотографиями.`}
                        />
                    ) : (
                        <CatalogEmpty
                            title="Модели не найдены"
                            description={`Среди моделей ${mark.name} нет совпадений. Измените запрос.`}
                        />
                    )
                ) : (
                    <div
                        className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6"
                        role="list"
                        aria-label={`Модели ${mark.name}`}
                    >
                        {visible.map((model) => (
                            <Attachment
                                key={model.href}
                                orientation="vertical"
                                role="listitem"
                                className="w-full has-data-[slot=attachment-content]:w-full"
                            >
                                <AttachmentMedia className="aspect-[4/3]">
                                    <CatalogPhoto
                                        src={model.image}
                                        alt={`${mark.name} ${model.name}`}
                                    />
                                </AttachmentMedia>
                                <AttachmentContent>
                                    <AttachmentTitle>
                                        {mark.name} {model.name}
                                    </AttachmentTitle>
                                    <AttachmentDescription className="tabular-nums">
                                        {formatYears(
                                            model.year_from,
                                            model.year_to,
                                        )}
                                    </AttachmentDescription>
                                </AttachmentContent>
                                <AttachmentTrigger asChild>
                                    <Link
                                        href={model.href}
                                        aria-label={`${mark.name} ${model.name}`}
                                    />
                                </AttachmentTrigger>
                            </Attachment>
                        ))}
                    </div>
                )}
            </PageBody>
        </>
    );
}

CatalogMark.layout = ({ mark }: CatalogMarkPage) => ({
    breadcrumbs: catalogBreadcrumbs(mark),
});
