import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    CatalogEmpty,
    CatalogSearch,
    MarkLogo,
    catalogBreadcrumbs,
    matchesSearch,
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
import type { CatalogMarksPage } from '@/types/knowledge-base';

function modelsLabel(count: number): string {
    const tens = count % 100;
    const units = count % 10;

    if (tens >= 11 && tens <= 14) {
        return `${count} моделей`;
    }

    if (units === 1) {
        return `${count} модель`;
    }

    return units >= 2 && units <= 4
        ? `${count} модели`
        : `${count} моделей`;
}

export default function CatalogMarks({ marks }: CatalogMarksPage) {
    const [search, setSearch] = useState('');
    const visible = useMemo(
        () =>
            marks.filter((mark) =>
                matchesSearch(search, mark.name, mark.name_ru),
            ),
        [marks, search],
    );

    return (
        <>
            <Head title="Каталог автомобилей" />
            <PageBody>
                <PageHeader
                    title="Каталог автомобилей"
                    description="Выберите марку, чтобы посмотреть модели, поколения и характеристики"
                    actions={
                        <CatalogSearch
                            value={search}
                            onChange={setSearch}
                            placeholder="Поиск марки"
                        />
                    }
                />

                {visible.length === 0 ? (
                    marks.length === 0 ? (
                        <CatalogEmpty
                            title="Каталог пока пуст"
                            description="Марки автомобилей появятся здесь после загрузки каталога."
                        />
                    ) : (
                        <CatalogEmpty
                            title="Марки не найдены"
                            description="Измените запрос: поиск идёт по латинскому и русскому названию марки."
                        />
                    )
                ) : (
                    <div
                        className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6"
                        role="list"
                        aria-label="Марки"
                    >
                        {visible.map((mark) => (
                            <Attachment
                                key={mark.href}
                                orientation="vertical"
                                role="listitem"
                                className="w-full has-data-[slot=attachment-content]:w-full"
                            >
                                <AttachmentMedia className="bg-background">
                                    <MarkLogo
                                        name={mark.name}
                                        logo={mark.logo}
                                        logoDark={mark.logo_dark}
                                        className="size-[70px] max-w-full"
                                    />
                                </AttachmentMedia>
                                <AttachmentContent>
                                    <AttachmentTitle>{mark.name}</AttachmentTitle>
                                    <AttachmentDescription>
                                        {modelsLabel(mark.models_count)}
                                    </AttachmentDescription>
                                </AttachmentContent>
                                <AttachmentTrigger asChild>
                                    <Link
                                        href={mark.href}
                                        aria-label={`${mark.name}: модели`}
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

CatalogMarks.layout = {
    breadcrumbs: catalogBreadcrumbs(),
};
