import { Search, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Option } from '@/types';

export function TableToolbar({
    search,
    onSearch,
    placeholder,
    isFiltered,
    onReset,
    children,
}: {
    search: string;
    onSearch: (value: string) => void;
    placeholder: string;
    isFiltered: boolean;
    onReset: () => void;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center gap-2.5">
            <div className="relative w-full sm:w-64">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    type="search"
                    value={search}
                    onChange={(event) => onSearch(event.target.value)}
                    placeholder={placeholder}
                    aria-label={placeholder}
                    maxLength={100}
                    className="bg-background ps-9"
                />
            </div>
            {children}
            {isFiltered && (
                <Button type="button" variant="ghost" onClick={onReset}>
                    <X />
                    Сбросить
                </Button>
            )}
        </div>
    );
}

export function FilterSelect({
    value,
    onChange,
    options,
    allLabel,
    label,
}: {
    value: string;
    onChange: (value: string) => void;
    options: Option[];
    allLabel: string;
    label: string;
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger
                className="w-full bg-background sm:w-44"
                aria-label={label}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent position="popper">
                <SelectGroup>
                    <SelectItem value="all">{allLabel}</SelectItem>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectGroup>
            </SelectContent>
        </Select>
    );
}

export const statusOptions: Option[] = [
    { value: 'active', label: 'Активные' },
    { value: 'inactive', label: 'Отключённые' },
];
