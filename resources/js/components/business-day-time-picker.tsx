import { ClockIcon } from 'lucide-react';
import type { TimePickerI18nOverrides } from '@/components/reui/time-picker';
import {
    TimePicker,
    TimePickerColumns,
    TimePickerConfirm,
    TimePickerContent,
    TimePickerFooter,
    TimePickerTrigger,
    TimePickerValue,
} from '@/components/reui/time-picker';

const I18N: TimePickerI18nOverrides = {
    labels: {
        placeholder: 'Выберите время',
        hour: 'Часы',
        minute: 'Минуты',
        second: 'Секунды',
        now: 'Сейчас',
        clear: 'Очистить',
        confirm: 'Готово',
        panelLabel: 'Выбор времени',
        openPicker: 'Открыть выбор времени',
    },
};

type Props = {
    id: string;
    value: string;
    onChange: (value: string) => void;
    timezone: string;
    disabled?: boolean;
    'aria-invalid'?: boolean;
};

/**
 * The boundary is a wall-clock time in the showroom timezone, not a conversion: the
 * zone is shown next to the value, and there is no Now, which would read the
 * viewer's clock.
 */
export function BusinessDayTimePicker({
    id,
    value,
    onChange,
    timezone,
    disabled,
    'aria-invalid': invalid,
}: Props) {
    return (
        <TimePicker
            id={id}
            value={value === '' ? null : value}
            onValueChange={(next) => onChange(next ?? '')}
            hourCycle={24}
            granularity="minute"
            disabled={disabled}
            invalid={invalid}
            aria-describedby={`${id}-zone`}
            i18n={I18N}
        >
            <TimePickerTrigger className="w-full">
                <TimePickerValue />
                <span
                    id={`${id}-zone`}
                    className="ms-auto text-muted-foreground"
                >
                    {timezone}
                </span>
                <span
                    data-icon="inline-end"
                    aria-hidden="true"
                    className="flex text-muted-foreground"
                >
                    <ClockIcon aria-hidden="true" />
                </span>
            </TimePickerTrigger>
            <TimePickerContent>
                <TimePickerColumns />
                <TimePickerFooter>
                    <TimePickerConfirm className="ms-auto" />
                </TimePickerFooter>
            </TimePickerContent>
        </TimePicker>
    );
}
