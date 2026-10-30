import { Head } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Pencil, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import SourceReferenceController from '@/actions/App/Http/Controllers/Sources/SourceReferenceController';
import { PageBody } from '@/components/organization/page-header';
import { RowActions } from '@/components/organization/row-actions';
import { ReferenceIcon } from '@/components/reference-icon';
import { StatusBadge } from '@/components/organization/status-badge';
import {
    recordCount,
    RegistryTable,
    searchFilter,
    statusFilter,
} from '@/components/registry-table';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import { SourceReferenceDialog } from '@/components/sources/source-reference-dialog';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import type { TableQueryState } from '@/hooks/use-table-query';
import { saveRowOrder } from '@/lib/submit';
import { index as referencesIndex } from '@/routes/references';
import { index as sourceReferencesIndex } from '@/routes/source-references';
import type {
    Paginated,
    ReferenceOption,
    SourceReferenceRow,
    SourceReferenceTypeValue,
} from '@/types';

type Props = {
    type: SourceReferenceTypeValue;
    title: string;
    singular: string;
    hasCode: boolean;
    tabs: { value: SourceReferenceTypeValue; title: string }[];
    items: Paginated<SourceReferenceRow>;
    filters: TableQueryState;
    classifications: ReferenceOption[];
    channels: ReferenceOption[];
    can: { manage: boolean };
};

const ONLY = ['items'];

export default function SourceReferencesIndex(props: Props) {
    return <ReferenceTable key={props.type} {...props} />;
}

function ReferenceTable({
    type,
    title,
    singular,
    hasCode,
    items,
    filters,
    classifications,
    channels,
    can,
}: Props) {
    const table = useTableQuery(sourceReferencesIndex.url(type), filters, ONLY);
    const [dialog, setDialog] = useState<{
        open: boolean;
        item: SourceReferenceRow | null;
    }>({ open: false, item: null });

    const columns = useMemo<ColumnDef<DataGridFeatures, SourceReferenceRow>[]>(
        () => [
            {
                accessorKey: 'name',
                header: 'Название',
                size: 240,
                cell: ({ row }) => (
                    <div className="flex min-w-0 items-center gap-2.5">
                        <ReferenceIcon
                            name={row.original.icon}
                            color={row.original.icon_color}
                        />
                        <div className="flex min-w-0 flex-col">
                            <span className="font-medium">
                                {row.original.name}
                            </span>
                            {row.original.code && (
                                <span className="text-xs text-muted-foreground">
                                    {row.original.code}
                                </span>
                            )}
                        </div>
                    </div>
                ),
            },
            ...(type === 'kinds'
                ? [
                      {
                          id: 'rules',
                          header: 'Разрешено',
                          size: 300,
                          cell: ({ row }) => (
                              <div className="flex flex-col gap-0.5 text-xs">
                                  <span>
                                      <span className="text-muted-foreground">
                                          Классификации:{' '}
                                      </span>
                                      {row.original.classification_names
                                          .length > 0
                                          ? row.original.classification_names.join(
                                                ', ',
                                            )
                                          : 'нет'}
                                  </span>
                                  <span>
                                      <span className="text-muted-foreground">
                                          Каналы:{' '}
                                      </span>
                                      {row.original.channel_names.length > 0
                                          ? row.original.channel_names.join(
                                                ', ',
                                            )
                                          : 'нет'}
                                  </span>
                              </div>
                          ),
                      } satisfies ColumnDef<
                          DataGridFeatures,
                          SourceReferenceRow
                      >,
                  ]
                : []),
            {
                accessorKey: 'sources_count',
                header: 'Источников',
                size: 110,
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
                    can.manage ? (
                        <RowActions
                            label={`Действия: ${row.original.name}`}
                            actions={[
                                {
                                    label: 'Изменить',
                                    icon: Pencil,
                                    onSelect: () =>
                                        setDialog({
                                            open: true,
                                            item: row.original,
                                        }),
                                },
                            ]}
                            status={{
                                active: row.original.active,
                                url: SourceReferenceController.status.url({
                                    type,
                                    id: row.original.id,
                                }),
                                lockVersion: row.original.lock_version,
                                title: `Отключить «${row.original.name}»?`,
                                description:
                                    'Источники сохранят это значение, но выбрать его заново будет нельзя. Значение можно включить снова.',
                                activateLabel: 'Включить',
                                deactivateLabel: 'Отключить',
                            }}
                        />
                    ) : null,
            },
        ],
        [type, can.manage],
    );

    return (
        <>
            <Head title={`Справочники источников — ${title}`} />
            <PageBody>
                <RegistryTable
                    title={title}
                    summary={[
                        recordCount(items.meta.total, table.isFiltered, [
                            'значение',
                            'значения',
                            'значений',
                        ]),
                        'Общий справочник для всех салонов. Новые значения не требуют изменения кода и не включают никакой интеграции',
                    ]}
                    filters={{
                        fields: [
                            searchFilter(
                                hasCode ? 'код или название…' : 'название…',
                            ),
                            statusFilter,
                        ],
                        conditions: table.query.filter,
                        onChange: table.setConditions,
                    }}
                    action={
                        can.manage && (
                            <Button
                                type="button"
                                onClick={() =>
                                    setDialog({ open: true, item: null })
                                }
                            >
                                <Plus />
                                Добавить
                            </Button>
                        )
                    }
                    rows={items}
                    columns={columns}
                    isLoading={table.isLoading}
                    failed={table.failed}
                    onPageChange={table.goToPage}
                    onRetry={table.reload}
                    emptyMessage={
                        table.isFiltered
                            ? 'По заданным условиям значения не найдены.'
                            : 'Значений пока нет.'
                    }
                    onReorder={
                        can.manage
                            ? (ids) =>
                                  saveRowOrder(
                                      SourceReferenceController.reorder.url(
                                          type,
                                      ),
                                      ids,
                                      ONLY,
                                  )
                            : undefined
                    }
                />
            </PageBody>

            <SourceReferenceDialog
                open={dialog.open}
                type={type}
                singular={singular}
                item={dialog.item}
                classifications={classifications}
                channels={channels}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
            />
        </>
    );
}

SourceReferencesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Справочники',
            href: referencesIndex(),
        },
    ],
};
