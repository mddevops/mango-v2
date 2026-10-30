import type {
    ColumnDef,
    PaginationState,
    Updater,
} from '@tanstack/react-table';
import { useTable } from '@tanstack/react-table';
import { RotateCw } from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo } from 'react';
import type { DataGridFeatures } from '@/components/reui/data-grid';
import { DataGrid, dataGridFeatures } from '@/components/reui/data-grid';
import { DataGridPagination } from '@/components/reui/data-grid-pagination';
import { DataGridScrollArea } from '@/components/reui/data-grid-scroll-area';
import { DataGridTable } from '@/components/reui/data-grid-table';
import {
    Alert,
    AlertAction,
    AlertDescription,
    AlertTitle,
} from '@/components/reui/alert';
import {
    Frame,
    FrameDescription,
    FrameFooter,
    FrameHeader,
    FramePanel,
    FrameTitle,
} from '@/components/reui/frame';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

const PAGE_SIZES = [10, 25, 50, 100];

const labels = {
    loading: 'Загрузка…',
    empty: 'Нет данных',
    rowsPerPage: 'Строк на странице',
    paginationInfo: ({
        from,
        to,
        count,
    }: {
        from: number;
        to: number;
        count: number;
    }) => `${from}–${to} из ${count}`,
    previousPage: 'Предыдущая страница',
    nextPage: 'Следующая страница',
    goToPage: (page: number) => `Страница ${page}`,
    sortAscending: 'По возрастанию',
    sortDescending: 'По убыванию',
    columnsMenu: 'Столбцы',
    toggleColumns: 'Показать столбцы',
};

type Props<TData extends { id: number }> = {
    title: string;
    description?: ReactNode;
    toolbar?: ReactNode;
    actions?: ReactNode;
    rows: Paginated<TData>;
    columns: ColumnDef<DataGridFeatures, TData>[];
    isLoading: boolean;
    failed: boolean;
    onPageChange: (page: number, perPage: number) => void;
    onRetry: () => void;
    emptyMessage: ReactNode;
    paginate?: boolean;
};

export function ServerDataGrid<TData extends { id: number }>({
    title,
    description,
    toolbar,
    actions,
    rows,
    columns,
    isLoading,
    failed,
    onPageChange,
    onRetry,
    emptyMessage,
    paginate = true,
}: Props<TData>) {
    const pagination = useMemo<PaginationState>(
        () => ({
            pageIndex: rows.meta.current_page - 1,
            pageSize: rows.meta.per_page,
        }),
        [rows.meta.current_page, rows.meta.per_page],
    );

    const table = useTable({
        features: dataGridFeatures,
        data: rows.data,
        columns,
        manualPagination: true,
        rowCount: rows.meta.total,
        state: { pagination },
        onPaginationChange: (updater: Updater<PaginationState>) => {
            const next =
                typeof updater === 'function' ? updater(pagination) : updater;
            onPageChange(next.pageIndex + 1, next.pageSize);
        },
        getRowId: (row) => String(row.id),
    });

    return (
        <DataGrid
            table={table}
            recordCount={rows.meta.total}
            isLoading={false}
            emptyMessage={emptyMessage}
            i18n={{ labels }}
        >
            <Frame className="w-full [--frame-radius:var(--radius-xl)]">
                <FrameHeader className="flex-row flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-col gap-0.5">
                        <FrameTitle>
                            <h1>{title}</h1>
                        </FrameTitle>
                        {description && (
                            <FrameDescription>{description}</FrameDescription>
                        )}
                    </div>
                    {(toolbar || actions) && (
                        <div className="flex flex-wrap items-center gap-2.5">
                            {toolbar}
                            {actions}
                        </div>
                    )}
                </FrameHeader>
                {failed ? (
                    <FramePanel>
                        <Alert variant="destructive">
                            <AlertTitle>Не удалось загрузить данные</AlertTitle>
                            <AlertDescription>
                                Проверьте соединение и повторите попытку.
                            </AlertDescription>
                            <AlertAction>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={onRetry}
                                >
                                    <RotateCw />
                                    Повторить
                                </Button>
                            </AlertAction>
                        </Alert>
                    </FramePanel>
                ) : (
                    <FramePanel
                        aria-busy={isLoading}
                        className={cn(
                            'p-0 transition-opacity duration-200',
                            isLoading && 'pointer-events-none opacity-60',
                        )}
                    >
                        <DataGridScrollArea orientation="horizontal">
                            <DataGridTable />
                        </DataGridScrollArea>
                    </FramePanel>
                )}
                {paginate && !failed && (
                    <FrameFooter
                        className={cn(
                            'py-1.5 pr-2 pl-2.5 transition-opacity duration-200',
                            isLoading && 'pointer-events-none opacity-60',
                        )}
                    >
                        <DataGridPagination sizes={PAGE_SIZES} />
                    </FrameFooter>
                )}
            </Frame>
        </DataGrid>
    );
}
