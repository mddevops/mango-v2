import { Head } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import {
    Briefcase,
    Building2,
    KeyRound,
    MessagesSquare,
    Pencil,
    Plus,
    Shapes,
    Tags,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import SourceController from '@/actions/App/Http/Controllers/Sources/SourceController';
import { PageBody } from '@/components/organization/page-header';
import { RowActions } from '@/components/organization/row-actions';
import { StatusBadge } from '@/components/organization/status-badge';
import {
    recordCount,
    RegistryTable,
    searchFilter,
    statusFilter,
} from '@/components/registry-table';
import type { RegistryFilter } from '@/components/registry-table';
import { Badge } from '@/components/reui/badge';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import { SiteIntakeDialog } from '@/components/sources/site-intake-dialog';
import { SourceDialog } from '@/components/sources/source-dialog';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import type { TableQueryState } from '@/hooks/use-table-query';
import { index as sourcesIndex } from '@/routes/sources';
import type {
    MangoConnectionOption,
    Paginated,
    ReferenceOption,
    SourceKindOption,
    SourceRow,
} from '@/types';

type Props = {
    sources: Paginated<SourceRow>;
    filters: TableQueryState;
    showrooms: ReferenceOption[];
    kinds: SourceKindOption[];
    classifications: ReferenceOption[];
    channels: ReferenceOption[];
    agencies: ReferenceOption[];
    mangoConnections: MangoConnectionOption[];
    can: { create: boolean; manageConnections: boolean };
};

const ONLY = ['sources'];

function toOptions(items: ReferenceOption[]) {
    return items.map((item) => ({ value: String(item.id), label: item.name }));
}

function Reference({
    item,
    disabledLabel,
}: {
    item: ReferenceOption;
    disabledLabel: string;
}) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <span>{item.name}</span>
            {!item.active && (
                <Badge variant="warning-light">{disabledLabel}</Badge>
            )}
        </div>
    );
}

export default function SourcesIndex({
    sources,
    filters,
    showrooms,
    kinds,
    classifications,
    channels,
    agencies,
    mangoConnections,
    can,
}: Props) {
    const table = useTableQuery(sourcesIndex.url(), filters, ONLY);
    const [dialog, setDialog] = useState<{
        open: boolean;
        source: SourceRow | null;
    }>({ open: false, source: null });
    const [intakeSource, setIntakeSource] = useState<SourceRow | null>(null);

    const canCreate =
        can.create &&
        showrooms.some((showroom) => showroom.active) &&
        kinds.some((kind) => kind.active);

    const showroomOptions = useMemo(() => toOptions(showrooms), [showrooms]);
    const kindOptions = useMemo(() => toOptions(kinds), [kinds]);
    const classificationOptions = useMemo(
        () => toOptions(classifications),
        [classifications],
    );
    const channelOptions = useMemo(() => toOptions(channels), [channels]);
    const agencyOptions = useMemo(() => toOptions(agencies), [agencies]);

    const filterFields = useMemo<RegistryFilter[]>(
        () => [
            searchFilter('название, код или адрес…'),
            {
                key: 'showroom',
                label: 'Салон',
                icon: Building2,
                type: 'select',
                options: showroomOptions,
            },
            {
                key: 'kind',
                label: 'Вид',
                icon: Shapes,
                type: 'select',
                options: kindOptions,
            },
            {
                key: 'classification',
                label: 'Классификация',
                icon: Tags,
                type: 'select',
                options: classificationOptions,
                emptiable: true,
            },
            {
                key: 'channel',
                label: 'Канал',
                icon: MessagesSquare,
                type: 'select',
                options: channelOptions,
                emptiable: true,
            },
            {
                key: 'agency',
                label: 'Агентство',
                icon: Briefcase,
                type: 'select',
                options: agencyOptions,
                emptiable: true,
            },
            statusFilter,
        ],
        [
            showroomOptions,
            kindOptions,
            classificationOptions,
            channelOptions,
            agencyOptions,
        ],
    );

    const columns = useMemo<ColumnDef<DataGridFeatures, SourceRow>[]>(
        () => [
            {
                accessorKey: 'name',
                header: 'Источник',
                size: 240,
                cell: ({ row }) => (
                    <div className="flex min-w-0 flex-col">
                        <span className="font-medium">{row.original.name}</span>
                        <span className="truncate text-xs text-muted-foreground">
                            {row.original.url ?? row.original.code}
                        </span>
                    </div>
                ),
            },
            {
                id: 'showroom',
                header: 'Салон',
                size: 170,
                cell: ({ row }) => (
                    <Reference
                        item={row.original.showroom}
                        disabledLabel="Отключён"
                    />
                ),
            },
            {
                id: 'kind',
                header: 'Вид и классификация',
                size: 190,
                cell: ({ row }) => (
                    <div className="flex flex-col gap-0.5">
                        <Reference
                            item={row.original.kind}
                            disabledLabel="Отключён"
                        />
                        <span className="text-xs text-muted-foreground">
                            {row.original.classification?.name ??
                                'Без классификации'}
                        </span>
                    </div>
                ),
            },
            {
                id: 'channels',
                header: 'Каналы',
                size: 220,
                cell: ({ row }) =>
                    row.original.channels.length > 0 ? (
                        <div className="flex flex-wrap gap-1">
                            {row.original.channels.map((channel) => (
                                <Badge key={channel.id} variant="outline">
                                    {channel.name}
                                </Badge>
                            ))}
                        </div>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    ),
            },
            {
                id: 'connections',
                header: 'Подключения',
                size: 180,
                cell: ({ row }) => {
                    const intake = row.original.site_intake;
                    const mango = row.original.mango;

                    if (!intake?.active && !mango) {
                        return <span className="text-muted-foreground">—</span>;
                    }

                    return (
                        <div className="flex flex-wrap gap-1">
                            {intake?.active &&
                                (intake.has_key === null ? (
                                    <Badge variant="info-light">API сайта</Badge>
                                ) : intake.has_key ? (
                                    <Badge variant="success-light">
                                        API сайта · ключ выпущен
                                    </Badge>
                                ) : (
                                    <Badge variant="warning-light">
                                        API сайта · нет ключа
                                    </Badge>
                                ))}
                            {mango && (
                                <Badge variant="warning-light">
                                    Mango · не проверено
                                </Badge>
                            )}
                        </div>
                    );
                },
            },
            {
                accessorKey: 'active',
                header: 'Статус',
                size: 110,
                cell: ({ row }) => <StatusBadge active={row.original.active} />,
            },
            {
                id: 'actions',
                header: () => <span className="sr-only">Действия</span>,
                size: 60,
                cell: ({ row }) =>
                    row.original.can.update ? (
                        <RowActions
                            label={`Действия: ${row.original.name}`}
                            actions={[
                                {
                                    label: 'Изменить',
                                    icon: Pencil,
                                    onSelect: () =>
                                        setDialog({
                                            open: true,
                                            source: row.original,
                                        }),
                                },
                                ...(can.manageConnections &&
                                !row.original.internal
                                    ? [
                                          {
                                              label: 'API сайта',
                                              icon: KeyRound,
                                              onSelect: () =>
                                                  setIntakeSource(row.original),
                                          },
                                      ]
                                    : []),
                            ]}
                            status={{
                                active: row.original.active,
                                url: SourceController.status.url(
                                    row.original.id,
                                ),
                                lockVersion: row.original.lock_version,
                                title: `Отключить источник «${row.original.name}»?`,
                                description:
                                    'Источник останется в списке и истории. Его можно включить снова.',
                                activateLabel: 'Включить',
                                deactivateLabel: 'Отключить',
                            }}
                        />
                    ) : null,
            },
        ],
        [can.manageConnections],
    );

    return (
        <>
            <Head title="Источники" />
            <PageBody>
                <RegistryTable
                    title="Источники"
                    summary={[
                        recordCount(sources.meta.total, table.isFiltered, [
                            'источник',
                            'источника',
                            'источников',
                        ]),
                        'Откуда приходят обращения: сайты, квизы, боты, партнёры и другие. Каждый источник относится к одному салону',
                    ]}
                    filters={{
                        fields: filterFields,
                        conditions: table.query.filter,
                        onChange: table.setConditions,
                    }}
                    action={
                        canCreate && (
                            <Button
                                type="button"
                                onClick={() =>
                                    setDialog({ open: true, source: null })
                                }
                            >
                                <Plus />
                                Добавить источник
                            </Button>
                        )
                    }
                    rows={sources}
                    columns={columns}
                    isLoading={table.isLoading}
                    failed={table.failed}
                    onPageChange={table.goToPage}
                    onRetry={table.reload}
                    emptyMessage={
                        table.isFiltered
                            ? 'По заданным условиям источники не найдены.'
                            : 'Источников пока нет.'
                    }
                />
            </PageBody>

            <SourceDialog
                open={dialog.open}
                source={dialog.source}
                showrooms={showrooms}
                kinds={kinds}
                classifications={classifications}
                channels={channels}
                agencies={agencies}
                mangoConnections={mangoConnections}
                canManageConnections={can.manageConnections}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
            />

            <SiteIntakeDialog
                source={intakeSource}
                open={intakeSource !== null}
                onOpenChange={(open) => !open && setIntakeSource(null)}
            />
        </>
    );
}

SourcesIndex.layout = {
    breadcrumbs: [{ title: 'Источники', href: sourcesIndex() }],
};
