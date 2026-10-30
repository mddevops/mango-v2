import { format, isValid, parse } from 'date-fns';
import { useState } from 'react';
import { ru } from 'react-day-picker/locale';
import {
    FILTER_DATE_FORMAT,
    resolveFilterDate,
    toFilterDateValue,
} from '@/components/reui/filters/filters-date';
import type { FilterDateValue } from '@/components/reui/filters/filters-date';
import type { FilterEditorProps } from '@/components/reui/filters/filters-types';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Input } from '@/components/ui/input';

/** One day, or a start and an end for «между». */
export type DateRuleValue = FilterDateValue | [FilterDateValue, FilterDateValue];

const TYPED_FORMAT = 'dd.MM.yyyy';

/** Seven pinned cells and tight weeks, so the panel is as wide as the grid (ReUI c-filters-5). */
const DAY_GRID =
    'p-0 [--cell-size:--spacing(7)] [&_.rdp-month]:gap-2 [&_tbody_tr]:mt-1';

const RANGE_PRESETS: [string, [FilterDateValue, FilterDateValue]][] = [
    [
        'Сегодня',
        [
            { relative: { unit: 'day', offset: 0 } },
            { relative: { unit: 'day', offset: 0 } },
        ],
    ],
    [
        '7 дней',
        [
            { relative: { unit: 'day', offset: -6 } },
            { relative: { unit: 'day', offset: 0 } },
        ],
    ],
    [
        '30 дней',
        [
            { relative: { unit: 'day', offset: -29 } },
            { relative: { unit: 'day', offset: 0 } },
        ],
    ],
];

export function isDateRange(
    value: unknown,
): value is [FilterDateValue, FilterDateValue] {
    return Array.isArray(value) && value.length === 2;
}

/** The day as the server takes it, 2026-10-22; relative picks resolve to today's dates. */
export function dateRuleDays(value: unknown): string[] {
    const items = isDateRange(value) ? value : [value as FilterDateValue];

    return items.flatMap((item) => {
        const date = resolveFilterDate(item);

        return date ? [format(date, FILTER_DATE_FORMAT)] : [];
    });
}

/** Stored conditions back into the editor's value. */
export function dateRuleValue(
    days: string[],
    range: boolean,
): DateRuleValue | undefined {
    const values = days.map((date) => ({ date }));

    if (range) {
        return values.length === 2 ? [values[0], values[1]] : undefined;
    }

    return values[0];
}

function dayText(value: FilterDateValue | undefined): string {
    const date = resolveFilterDate(value);

    return date ? format(date, TYPED_FORMAT) : '';
}

export function dateRuleText(value: unknown): string {
    if (isDateRange(value)) {
        return `${dayText(value[0])} — ${dayText(value[1])}`;
    }

    return dayText(value as FilterDateValue | undefined) || 'не задано';
}

function SingleDate({
    value,
    onValueChange,
    commit,
    cancel,
    autoFocusProps,
    field,
}: FilterEditorProps<DateRuleValue>) {
    const current = isDateRange(value) ? value[0] : value;
    const [text, setText] = useState(() => dayText(current));
    const selected = resolveFilterDate(current) ?? undefined;
    const parseTyped = (input: string) => {
        const date = parse(input.trim(), TYPED_FORMAT, new Date());

        return isValid(date) ? toFilterDateValue(date) : null;
    };

    return (
        <div className="flex flex-col gap-2 p-2">
            <Input
                {...autoFocusProps}
                value={text}
                placeholder="ДД.ММ.ГГГГ"
                aria-label={field.label}
                onChange={(event) => {
                    setText(event.target.value);
                    const parsed = parseTyped(event.target.value);

                    if (parsed) {
                        onValueChange(parsed);
                    }
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        commit(parseTyped(text) ?? current);
                    }

                    if (event.key === 'Escape') {
                        event.preventDefault();
                        event.stopPropagation();
                        cancel();
                    }
                }}
            />
            <Calendar
                mode="single"
                locale={ru}
                className={DAY_GRID}
                selected={selected}
                defaultMonth={selected}
                onSelect={(date) => {
                    if (date) {
                        commit(toFilterDateValue(date));
                    }
                }}
            />
        </div>
    );
}

function DateRange({
    value,
    onValueChange,
    commit,
    cancel,
    autoFocusProps,
    labels,
}: FilterEditorProps<DateRuleValue>) {
    const tuple = isDateRange(value) ? value : undefined;
    const from = resolveFilterDate(tuple?.[0]) ?? undefined;
    const to = resolveFilterDate(tuple?.[1]) ?? undefined;

    return (
        <div className="flex flex-col gap-2 p-2">
            <div className="grid grid-cols-3 gap-1.5">
                {RANGE_PRESETS.map(([label, preset], index) => (
                    <Button
                        key={label}
                        {...(index === 0 ? autoFocusProps : {})}
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => commit(preset)}
                    >
                        {label}
                    </Button>
                ))}
            </div>
            <Calendar
                mode="range"
                locale={ru}
                numberOfMonths={2}
                className={DAY_GRID}
                selected={{ from, to }}
                defaultMonth={from}
                onSelect={(range) => {
                    if (!range?.from) {
                        return;
                    }

                    onValueChange([
                        toFilterDateValue(range.from),
                        toFilterDateValue(range.to ?? range.from),
                    ]);
                }}
            />
            <div className="-mx-2 flex items-center justify-end gap-1.5 border-t px-2 pt-2">
                <Button type="button" variant="ghost" size="sm" onClick={cancel}>
                    {labels.discard}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    disabled={!tuple}
                    onClick={() => commit()}
                >
                    {labels.apply}
                </Button>
            </div>
        </div>
    );
}

/** One calendar for a day, two months for «между» (ReUI c-filters-5). */
export function DateRuleEditor(props: FilterEditorProps<DateRuleValue>) {
    return props.operator.arity === 'range' ? (
        <DateRange {...props} />
    ) : (
        <SingleDate {...props} />
    );
}
