import { CalendarClockIcon } from 'lucide-react';
import { useState } from 'react';
import { ru } from 'react-day-picker/locale';
import type { TimePickerI18nOverrides } from '@/components/reui/time-picker';
import {
    TimePicker,
    TimePickerColumns,
    TimePickerContent,
    TimePickerFooter,
    TimePickerTrigger,
} from '@/components/reui/time-picker';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';

const I18N: TimePickerI18nOverrides = {
    labels: {
        placeholder: 'Выберите дату и время',
        hour: 'Часы',
        minute: 'Минуты',
        second: 'Секунды',
        now: 'Сейчас',
        clear: 'Очистить',
        confirm: 'Готово',
        panelLabel: 'Выбор даты и времени',
        openPicker: 'Открыть выбор даты и времени',
    },
};

const DEFAULT_TIME = '10:00';

type Props = {
    id: string;
    /** `YYYY-MM-DDTHH:mm` in the showroom's wall-clock time, or ''. */
    value: string;
    onChange: (value: string) => void;
    'aria-invalid'?: boolean;
};

function pad(value: number) {
    return String(value).padStart(2, '0');
}

function toDate(value: string): Date | undefined {
    const match = /^(\d{4})-(\d{2})-(\d{2})T/.exec(value);

    return match
        ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
        : undefined;
}

function toTime(value: string): string | null {
    return /T(\d{2}:\d{2})$/.exec(value)?.[1] ?? null;
}

function compose(date: Date, time: string) {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${time}`;
}

function label(value: string) {
    const date = toDate(value);
    const time = toTime(value);

    return date && time
        ? `${pad(date.getDate())}.${pad(date.getMonth() + 1)}.${date.getFullYear()} ${time}`
        : null;
}

/**
 * A day and a time picked together and applied with «Готово»; closing the popover
 * discards the draft. The value is a wall-clock time without a zone.
 */
export function DateTimePicker({
    id,
    value,
    onChange,
    'aria-invalid': invalid,
}: Props) {
    const [open, setOpen] = useState(false);
    const [date, setDate] = useState<Date | undefined>(() => toDate(value));
    const [time, setTime] = useState<string | null>(() => toTime(value));
    const text = label(value);

    const changeOpen = (next: boolean) => {
        setDate(toDate(value));
        setTime(toTime(value));
        setOpen(next);
    };

    const apply = () => {
        if (date) {
            onChange(compose(date, time ?? DEFAULT_TIME));
        }

        setOpen(false);
    };

    const clear = () => {
        onChange('');
        setOpen(false);
    };

    return (
        <TimePicker
            id={id}
            value={time}
            onValueChange={setTime}
            open={open}
            onOpenChange={changeOpen}
            hourCycle={24}
            minuteStep={5}
            invalid={invalid}
            i18n={I18N}
        >
            <TimePickerTrigger className="w-full">
                <span className="truncate">
                    {text ?? I18N.labels?.placeholder}
                </span>
                <span
                    data-icon="inline-end"
                    aria-hidden="true"
                    className="flex text-muted-foreground"
                >
                    <CalendarClockIcon aria-hidden="true" />
                </span>
            </TimePickerTrigger>
            <TimePickerContent>
                <div className="flex flex-col gap-4 p-3 sm:flex-row sm:items-start">
                    <Calendar
                        mode="single"
                        locale={ru}
                        selected={date}
                        onSelect={setDate}
                        defaultMonth={date}
                        className="p-0"
                    />
                    <div className="flex justify-center self-stretch max-sm:border-t max-sm:pt-4 sm:border-s sm:ps-4">
                        <TimePickerColumns className="[--time-picker-rows:7]" />
                    </div>
                </div>
                <TimePickerFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={clear}
                    >
                        Очистить
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        className="ms-auto"
                        onClick={apply}
                        disabled={!date}
                    >
                        Готово
                    </Button>
                </TimePickerFooter>
            </TimePickerContent>
        </TimePicker>
    );
}
