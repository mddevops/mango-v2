import { Plus, X } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { useState } from 'react';
import { Badge } from '@/components/reui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    formatRuPhone,
    isCompleteRuPhone,
    RU_PHONE_PLACEHOLDER,
} from '@/lib/ru-phone';

export type PhoneRow = { number: string; name: string };

/** Rows as the form keeps them; blank additional rows are dropped before sending. */
export function toPhoneRows(
    phones: { formatted: string; name: string | null }[],
): PhoneRow[] {
    return phones.length === 0
        ? [{ number: '', name: '' }]
        : phones.map((phone) => ({
              number: formatRuPhone(phone.formatted),
              name: phone.name ?? '',
          }));
}

export function phonesPayload(rows: PhoneRow[]) {
    return rows
        .filter((row, index) => index === 0 || row.number.trim() !== '')
        .map((row, index) => ({
            number: row.number,
            name: index === 0 || row.name.trim() === '' ? null : row.name,
        }));
}

export function RuPhoneInput({
    value,
    onValueChange,
    ...props
}: Omit<ComponentProps<typeof Input>, 'value' | 'onChange' | 'type'> & {
    value: string;
    onValueChange: (value: string) => void;
}) {
    return (
        <Input
            type="tel"
            inputMode="tel"
            autoComplete="off"
            placeholder={RU_PHONE_PLACEHOLDER}
            maxLength={18}
            value={value}
            onChange={(event) => onValueChange(formatRuPhone(event.target.value))}
            {...props}
        />
    );
}

/**
 * The main number in the form and up to `maxExtra` additional numbers, each with an
 * optional owner name, in a popover. Server errors arrive for the rows actually sent,
 * so they are mapped back past blank rows.
 */
export function OrderPhonesField({
    phones,
    maxExtra,
    errors,
    disabled,
    onChange,
    beside,
}: {
    phones: PhoneRow[];
    maxExtra: number;
    errors: Record<string, string | undefined>;
    disabled?: boolean;
    onChange: (phones: PhoneRow[]) => void;
    /** A field put before the main number in one row, with a compact add button after it. */
    beside?: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [main, ...extras] = phones;
    const filled = extras.filter((row) => isCompleteRuPhone(row.number)).length;

    const sentIndex = (extraIndex: number) => {
        if (extras[extraIndex].number.trim() === '') {
            return null;
        }

        return (
            extras
                .slice(0, extraIndex)
                .filter((row) => row.number.trim() !== '').length + 1
        );
    };

    const extraError = (extraIndex: number, key: 'number' | 'name') => {
        const index = sentIndex(extraIndex);

        return index === null ? undefined : errors[`phones.${index}.${key}`];
    };

    const extrasInvalid = extras.some(
        (_, index) => extraError(index, 'number') || extraError(index, 'name'),
    );

    const setExtras = (next: PhoneRow[]) => onChange([main, ...next]);

    const updateExtra = (index: number, patch: Partial<PhoneRow>) =>
        setExtras(
            extras.map((row, at) => (at === index ? { ...row, ...patch } : row)),
        );

    const openChange = (next: boolean) => {
        setOpen(next);

        if (next && extras.length === 0) {
            setExtras([{ number: '', name: '' }]);
        }

        if (!next) {
            setExtras(extras.filter((row) => row.number.trim() !== ''));
        }
    };

    const mainInput = (
        <RuPhoneInput
            id="order-phone-main"
            className={beside ? undefined : 'min-w-48 flex-1'}
            value={main.number}
            onValueChange={(number) => onChange([{ ...main, number }, ...extras])}
            disabled={disabled}
            required
            aria-invalid={errors['phones.0.number'] ? true : undefined}
        />
    );

    const mainLabel = (
        <FieldLabel htmlFor="order-phone-main">
            Телефон<span className="text-destructive">*</span>
        </FieldLabel>
    );

    const mainError = (
        <FieldError>{errors['phones.0.number'] ?? errors.phones}</FieldError>
    );

    // Always rendered, so the button keeps its width when the count appears.
    const countBadge = (
        <Badge
            variant={extrasInvalid ? 'destructive' : 'success'}
            size="sm"
            aria-hidden="true"
            className={filled === 0 ? 'invisible' : undefined}
        >
            {filled}
        </Badge>
    );

    const extrasContent = (
        <PopoverContent align="end" className="w-xl max-w-[calc(100vw-2rem)] px-3 py-2">
            <PopoverHeader>
                <PopoverTitle>Дополнительные телефоны</PopoverTitle>
                <PopoverDescription>
                    Имя владельца номера можно не указывать.
                </PopoverDescription>
            </PopoverHeader>
            <div className="flex flex-col gap-3">
                {extras.map((row, index) => (
                    <div key={index} className="flex flex-col gap-1">
                        <div className="grid grid-cols-2 items-center gap-2">
                            <Input
                                aria-label={`Имя клиента, номер ${index + 1}`}
                                placeholder="Имя клиента"
                                value={row.name}
                                onChange={(event) =>
                                    updateExtra(index, {
                                        name: event.target.value,
                                    })
                                }
                                maxLength={100}
                                className="min-w-0"
                                aria-invalid={
                                    extraError(index, 'name') ? true : undefined
                                }
                            />
                            <div className="flex min-w-0 items-center gap-2">
                            <RuPhoneInput
                                aria-label={`Номер ${index + 1}`}
                                value={row.number}
                                onValueChange={(number) =>
                                    updateExtra(index, { number })
                                }
                                className="min-w-0 flex-1"
                                aria-invalid={
                                    extraError(index, 'number')
                                        ? true
                                        : undefined
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Удалить номер"
                                onClick={() =>
                                    setExtras(
                                        extras.filter((_, at) => at !== index),
                                    )
                                }
                            >
                                <X />
                            </Button>
                            </div>
                        </div>
                        <FieldError>
                            {extraError(index, 'number') ??
                                extraError(index, 'name')}
                        </FieldError>
                    </div>
                ))}
                <div className="grid grid-cols-2 items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        disabled={extras.length >= maxExtra}
                        onClick={() =>
                            setExtras([...extras, { number: '', name: '' }])
                        }
                    >
                        <Plus />
                        Ещё номер
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => openChange(false)}
                    >
                        Готово
                    </Button>
                </div>
            </div>
        </PopoverContent>
    );

    if (beside) {
        return (
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
                <div className="sm:flex-1">{beside}</div>
                <Field
                    className="sm:flex-1"
                    data-invalid={errors['phones.0.number'] ? true : undefined}
                >
                    {mainLabel}
                    {mainInput}
                    {mainError}
                </Field>
                <div className="flex shrink-0 flex-col gap-3">
                    <FieldLabel
                        aria-hidden="true"
                        className="invisible max-sm:hidden"
                    >
                        Телефон
                    </FieldLabel>
                    <Popover open={open} onOpenChange={openChange}>
                        <PopoverTrigger asChild>
                            <Button
                                type="button"
                                variant="outline"
                                className="gap-2"
                                disabled={disabled}
                                aria-label={
                                    filled > 0
                                        ? `Дополнительные телефоны (${filled})`
                                        : 'Добавить телефон'
                                }
                            >
                                <Plus aria-hidden="true" />
                                Добавить ещё
                                {countBadge}
                            </Button>
                        </PopoverTrigger>
                        {extrasContent}
                    </Popover>
                </div>
            </div>
        );
    }

    const extrasPopover = (
        <Popover open={open} onOpenChange={openChange}>
            <PopoverTrigger asChild>
                <Button type="button" variant="outline" disabled={disabled}>
                    <Plus />
                    Дополнительный телефон
                    {countBadge}
                </Button>
            </PopoverTrigger>
            {extrasContent}
        </Popover>
    );

    return (
        <Field data-invalid={errors['phones.0.number'] ? true : undefined}>
            {mainLabel}
            <div className="flex flex-wrap items-center gap-2">
                {mainInput}
                {extrasPopover}
            </div>
            {mainError}
        </Field>
    );
}
