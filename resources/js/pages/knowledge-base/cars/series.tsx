import { Head, Link, router } from '@inertiajs/react';
import {
    CatalogEmpty,
    SeriesHeader,
    catalogBreadcrumbs,
    seriesTitle,
} from '@/components/knowledge-base/catalog';
import { PageBody } from '@/components/organization/page-header';
import { Frame, FramePanel } from '@/components/reui/frame';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type {
    CatalogEquipmentRow,
    CatalogModificationRow,
    CatalogSeriesPage,
} from '@/types/knowledge-base';

function groupBy<T>(rows: T[], key: (row: T) => string) {
    const groups: { key: string; rows: T[] }[] = [];

    for (const row of rows) {
        const last = groups.at(-1);

        if (last && last.key === key(row)) {
            last.rows.push(row);
        } else {
            groups.push({ key: key(row), rows: [row] });
        }
    }

    return groups;
}

function ModificationsTable({ rows }: { rows: CatalogModificationRow[] }) {
    if (rows.length === 0) {
        return (
            <CatalogEmpty
                title="Модификаций пока нет"
                description="Для этого кузова в каталоге ещё нет модификаций с двигателем, коробкой и приводом."
            />
        );
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Двигатель</TableHead>
                    <TableHead>Коробка</TableHead>
                    <TableHead>Привод</TableHead>
                    <TableHead>Расход, л / 100 км</TableHead>
                    <TableHead>Разгон до 100</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {groupBy(rows, (row) => row.engine).map((group) =>
                    group.rows.map((row, index) => (
                        <TableRow
                            key={row.href}
                            className="cursor-pointer"
                            onClick={() => router.visit(row.href)}
                        >
                            {index === 0 && (
                                <TableCell
                                    rowSpan={group.rows.length}
                                    className="cursor-default align-top font-medium"
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    {group.key || '—'}
                                </TableCell>
                            )}
                            <TableCell>
                                <Link
                                    href={row.href}
                                    className="text-primary hover:underline"
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    {row.transmission ?? 'Характеристики'}
                                </Link>
                            </TableCell>
                            <TableCell>{row.drive ?? '—'}</TableCell>
                            <TableCell className="tabular-nums">
                                {row.consumption ?? '—'}
                            </TableCell>
                            <TableCell className="tabular-nums">
                                {row.acceleration ?? '—'}
                            </TableCell>
                        </TableRow>
                    )),
                )}
            </TableBody>
        </Table>
    );
}

function EquipmentsTable({ rows }: { rows: CatalogEquipmentRow[] }) {
    if (rows.length === 0) {
        return (
            <CatalogEmpty
                title="Комплектаций пока нет"
                description="Для этого кузова в каталоге ещё нет комплектаций с перечнем опций."
            />
        );
    }

    const groups = groupBy(
        [...rows].sort((a, b) => a.name.localeCompare(b.name, 'ru')),
        (row) => row.name,
    );

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Комплектация</TableHead>
                    <TableHead>Модификация</TableHead>
                    <TableHead className="text-right">Опций</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {groups.map((group) =>
                    group.rows.map((row, index) => (
                        <TableRow
                            key={row.href}
                            className="cursor-pointer"
                            onClick={() => router.visit(row.href)}
                        >
                            {index === 0 && (
                                <TableCell
                                    rowSpan={group.rows.length}
                                    className="cursor-default align-top font-medium"
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    {group.key}
                                </TableCell>
                            )}
                            <TableCell>
                                <Link
                                    href={row.href}
                                    className="text-primary hover:underline"
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    {row.modification}
                                </Link>
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {row.options_count}
                            </TableCell>
                        </TableRow>
                    )),
                )}
            </TableBody>
        </Table>
    );
}

export default function CatalogSeries(props: CatalogSeriesPage) {
    const { modifications, equipments } = props;

    return (
        <>
            <Head title={`${seriesTitle(props)} ${props.series.name}`} />
            <PageBody>
                <SeriesHeader context={props} />

                <Tabs defaultValue="modifications">
                    <TabsList>
                        <TabsTrigger value="modifications">
                            Модификации
                            <span className="text-muted-foreground tabular-nums">
                                {modifications.length}
                            </span>
                        </TabsTrigger>
                        <TabsTrigger value="equipments">
                            Комплектации
                            <span className="text-muted-foreground tabular-nums">
                                {equipments.length}
                            </span>
                        </TabsTrigger>
                    </TabsList>
                    <TabsContent value="modifications">
                        <Frame>
                            <FramePanel className="py-2">
                                <ModificationsTable rows={modifications} />
                            </FramePanel>
                        </Frame>
                    </TabsContent>
                    <TabsContent value="equipments">
                        <Frame>
                            <FramePanel className="py-2">
                                <EquipmentsTable rows={equipments} />
                            </FramePanel>
                        </Frame>
                    </TabsContent>
                </Tabs>
            </PageBody>
        </>
    );
}

CatalogSeries.layout = (props: CatalogSeriesPage) => ({
    breadcrumbs: catalogBreadcrumbs(props.mark, props.model, {
        name: `${props.series.generation.name} ${props.series.name}`,
        href: props.series.href,
    }),
});
