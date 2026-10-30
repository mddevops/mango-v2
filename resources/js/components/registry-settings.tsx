'use no memo';

import { Check, LayoutDashboard, List, Settings2 } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId } from 'react';
import { getColumnHeaderLabel, useDataGrid } from '@/components/reui/data-grid';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';

export type RegistryDensity = 'default' | 'compact';
export type RegistryView = 'table' | 'cards';

const DENSITIES: { value: RegistryDensity; label: string }[] = [
    { value: 'default', label: 'Обычная' },
    { value: 'compact', label: 'Компактная' },
];

const VIEWS: { value: RegistryView; label: string; icon: LucideIcon }[] = [
    { value: 'table', label: 'Таблица', icon: List },
    { value: 'cards', label: 'Карточки', icon: LayoutDashboard },
];

function SettingRow({
    id,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
}) {
    return (
        <Field
            orientation="horizontal"
            className="min-h-9 items-center justify-between gap-3"
        >
            <FieldLabel htmlFor={id} className="text-sm font-normal">
                {label}
            </FieldLabel>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger
                    id={id}
                    size="sm"
                    className="w-33 shrink-0 rounded-lg"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent
                    position="popper"
                    align="end"
                    className="rounded-lg"
                >
                    {options.map((option) => (
                        <SelectItem
                            key={option.value}
                            value={option.value}
                            className="rounded-md"
                        >
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </Field>
    );
}

/**
 * «Настройки» of a registry grid: row density, table or cards, and the hideable columns.
 * Rendered inside the grid, as it reads the columns from it.
 */
export function RegistrySettings({
    density,
    onDensityChange,
    view,
    onViewChange,
}: {
    density: RegistryDensity;
    onDensityChange: (density: RegistryDensity) => void;
    view?: RegistryView;
    onViewChange?: (view: RegistryView) => void;
}) {
    const { table } = useDataGrid();
    const id = useId();
    const columns = table
        .getAllColumns()
        .filter((column) => column.getCanHide());

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    aria-label="Настройки таблицы"
                >
                    <Settings2 aria-hidden="true" />
                    Настройки
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80 p-0">
                <div className="flex flex-col gap-3 px-3.5 py-3">
                    <div className="flex flex-col gap-2">
                        <div className="text-xs font-medium text-muted-foreground">
                            Таблица
                        </div>
                        <div>
                            {view && onViewChange && (
                                <Field
                                    orientation="horizontal"
                                    className="min-h-9 items-center justify-between gap-3"
                                >
                                    <FieldLabel
                                        id={`${id}-view`}
                                        className="text-sm font-normal"
                                    >
                                        Показать
                                    </FieldLabel>
                                    <ToggleGroup
                                        type="single"
                                        variant="outline"
                                        spacing={0}
                                        value={view}
                                        onValueChange={(value) => {
                                            if (value === 'table' || value === 'cards') {
                                                onViewChange(value);
                                            }
                                        }}
                                        aria-labelledby={`${id}-view`}
                                        className="shrink-0 data-[spacing=0]:data-[variant=outline]:rounded-lg"
                                    >
                                        {VIEWS.map(({ value, label, icon: Icon }) => (
                                            <ToggleGroupItem
                                                key={value}
                                                value={value}
                                                aria-label={label}
                                                title={label}
                                                className="h-8 min-w-8 px-2! group-data-horizontal/toggle-group:data-[spacing=0]:first:rounded-l-lg group-data-horizontal/toggle-group:data-[spacing=0]:last:rounded-r-lg"
                                            >
                                                <Icon aria-hidden="true" />
                                            </ToggleGroupItem>
                                        ))}
                                    </ToggleGroup>
                                </Field>
                            )}
                            {view !== 'cards' && (
                                <SettingRow
                                    id={`${id}-density`}
                                    label="Плотность"
                                    value={density}
                                    options={DENSITIES}
                                    onChange={(value) =>
                                        onDensityChange(value as RegistryDensity)
                                    }
                                />
                            )}
                        </div>
                    </div>
                    {columns.length > 0 && (
                        <>
                            <Separator className="-mx-3.5 w-auto!" />
                            <div className="flex flex-col gap-2.5">
                                <div className="text-xs font-medium text-muted-foreground">
                                    Столбцы таблицы
                                </div>
                                <div className="flex flex-wrap gap-1.5">
                                    {columns.map((column) => {
                                        const visible = column.getIsVisible();

                                        return (
                                            <Button
                                                key={column.id}
                                                type="button"
                                                size="xs"
                                                variant={
                                                    visible
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                                className={cn(
                                                    'rounded-full',
                                                    visible &&
                                                        'border-foreground/10',
                                                )}
                                                aria-pressed={visible}
                                                onClick={() =>
                                                    column.toggleVisibility()
                                                }
                                            >
                                                {visible && (
                                                    <Check
                                                        className="size-3.5"
                                                        aria-hidden="true"
                                                    />
                                                )}
                                                {getColumnHeaderLabel(column)}
                                            </Button>
                                        );
                                    })}
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}
