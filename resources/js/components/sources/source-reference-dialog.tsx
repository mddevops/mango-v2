import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import SourceReferenceController from '@/actions/App/Http/Controllers/Sources/SourceReferenceController';
import FormField from '@/components/form-field';
import { FormAlert } from '@/components/organization/form-alert';
import type { FormDialogControls } from '@/components/organization/form-dialog';
import { FormDialog } from '@/components/organization/form-dialog';
import { CheckboxOption } from '@/components/organization/form-section';
import { TechnicalCode } from '@/components/organization/technical-code';
import {
    IconColorPicker,
    isIconName,
    ReferenceIcon,
} from '@/components/reference-icon';
import { Badge } from '@/components/reui/badge';
import { Button } from '@/components/ui/button';
import {
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { saveOptions } from '@/lib/submit';
import type {
    IconColor,
    ReferenceOption,
    SourceReferenceRow,
    SourceReferenceTypeValue,
} from '@/types';

type Props = {
    open: boolean;
    type: SourceReferenceTypeValue;
    singular: string;
    item: SourceReferenceRow | null;
    classifications: ReferenceOption[];
    channels: ReferenceOption[];
    onOpenChange: (open: boolean) => void;
};

export function SourceReferenceDialog({ open, onOpenChange, ...props }: Props) {
    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            className={
                props.type === 'kinds'
                    ? 'max-h-[90vh] overflow-y-auto sm:max-w-2xl'
                    : undefined
            }
        >
            {(controls) => (
                <SourceReferenceForm {...props} controls={controls} />
            )}
        </FormDialog>
    );
}

function RuleOptions({
    prefix,
    title,
    hint,
    items,
    selected,
    error,
    onChange,
}: {
    prefix: string;
    title: string;
    hint: string;
    items: ReferenceOption[];
    selected: number[];
    error?: string;
    onChange: (ids: number[]) => void;
}) {
    const visible = items.filter(
        (item) => item.active || selected.includes(item.id),
    );

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-col gap-0.5">
                <span className="text-sm font-medium">{title}</span>
                <span className="text-xs text-muted-foreground">{hint}</span>
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
                {visible.map((item) => (
                    <CheckboxOption
                        key={item.id}
                        id={`${prefix}-${item.id}`}
                        checked={selected.includes(item.id)}
                        onChange={(checked) =>
                            onChange(
                                checked
                                    ? [...selected, item.id]
                                    : selected.filter((id) => id !== item.id),
                            )
                        }
                        label={item.name}
                        aside={
                            !item.active && (
                                <Badge variant="warning-light">Отключено</Badge>
                            )
                        }
                    />
                ))}
            </div>
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}

function SourceReferenceForm({
    type,
    singular,
    item,
    classifications,
    channels,
    controls,
}: Omit<Props, 'open' | 'onOpenChange'> & {
    controls: FormDialogControls;
}) {
    const { setBusy, setDirty, requestClose, close } = controls;
    const [failure, setFailure] = useState<string | null>(null);
    const isKind = type === 'kinds';
    const hasIcon = type === 'kinds' || type === 'channels';
    const form = useForm({
        name: item?.name ?? '',
        icon: item?.icon ?? '',
        show_icon: item?.show_icon ?? false,
        icon_color: (item?.icon_color ?? 'gray') as IconColor,
        classification_ids: item?.classification_ids ?? [],
        channel_ids: item?.channel_ids ?? [],
        lock_version: item?.lock_version ?? 0,
    });

    useEffect(() => setDirty(form.isDirty), [form.isDirty, setDirty]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        const options = saveOptions(setFailure, {
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => close(),
        });

        form.transform((data) => ({
            name: data.name,
            ...(hasIcon
                ? {
                      icon: data.icon.trim() || null,
                      show_icon: data.icon.trim() !== '' && data.show_icon,
                      icon_color: data.icon_color,
                  }
                : {}),
            ...(isKind
                ? {
                      classification_ids: data.classification_ids,
                      channel_ids: data.channel_ids,
                  }
                : {}),
            ...(item ? { lock_version: data.lock_version } : {}),
        }));

        if (item) {
            form.put(
                SourceReferenceController.update.url({ type, id: item.id }),
                options,
            );
        } else {
            form.post(SourceReferenceController.store.url(type), options);
        }
    };

    const errors = form.errors as Record<string, string | undefined>;
    const iconValue = form.data.icon.trim();

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <DialogHeader>
                <DialogTitle>
                    {item
                        ? `${singular}: изменение`
                        : `${singular}: новое значение`}
                </DialogTitle>
                <DialogDescription>
                    Справочник общий для всех салонов. Значения не удаляются —
                    их можно отключить. Порядок в списках меняется
                    перетаскиванием строк таблицы.
                </DialogDescription>
            </DialogHeader>

            <FormAlert conflict={form.errors.lock_version} failure={failure} />

            <div className="flex flex-col gap-4">
                <FormField label="Название" error={form.errors.name}>
                    <Input
                        id="reference-name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        required
                        maxLength={type === 'agencies' ? 255 : 100}
                        autoFocus
                    />
                </FormField>
                {hasIcon && (
                    <>
                        <FormField
                            label="Иконка"
                            error={errors.icon}
                            description={
                                <>
                                    Название иконки с{' '}
                                    <a
                                        href="https://lucide.dev/icons"
                                        target="_blank"
                                        rel="noreferrer"
                                        className="underline underline-offset-4 hover:text-foreground"
                                    >
                                        lucide.dev/icons
                                    </a>
                                    , например smartphone-charging.
                                    {iconValue !== '' &&
                                        !isIconName(iconValue) &&
                                        ' Такой иконки нет — проверьте название.'}
                                </>
                            }
                        >
                            <div className="flex items-center gap-2.5">
                                {isIconName(iconValue) ? (
                                    <ReferenceIcon
                                        name={iconValue}
                                        color={form.data.icon_color}
                                    />
                                ) : (
                                    <div className="size-8 shrink-0 rounded-lg border border-dashed" />
                                )}
                                <Input
                                    id="reference-icon"
                                    value={form.data.icon}
                                    onChange={(event) =>
                                        form.setData(
                                            'icon',
                                            event.target.value.toLowerCase(),
                                        )
                                    }
                                    maxLength={64}
                                    placeholder="smartphone-charging"
                                    autoComplete="off"
                                    spellCheck={false}
                                />
                            </div>
                        </FormField>
                        <FormField
                            label="Цвет иконки"
                            error={errors.icon_color}
                        >
                            <IconColorPicker
                                value={form.data.icon_color}
                                onChange={(color) =>
                                    form.setData('icon_color', color)
                                }
                                disabled={!isIconName(iconValue)}
                            />
                        </FormField>
                        <CheckboxOption
                            id="reference-show-icon"
                            checked={form.data.show_icon}
                            disabled={!isIconName(iconValue)}
                            onChange={(checked) =>
                                form.setData('show_icon', checked)
                            }
                            label="Показывать иконку в таблице заявок"
                            hint={
                                isKind
                                    ? 'В колонке «Источник»: иконка, название источника и вид под ним.'
                                    : 'В колонке «Тип заявки» рядом с названием.'
                            }
                        />
                    </>
                )}
                {isKind && (
                    <>
                        <RuleOptions
                            prefix="kind-classification"
                            title="Разрешённые классификации"
                            hint="Можно не выбирать ни одной: тогда источники этого вида создаются без классификации."
                            items={classifications}
                            selected={form.data.classification_ids}
                            error={errors.classification_ids}
                            onChange={(ids) =>
                                form.setData('classification_ids', ids)
                            }
                        />
                        <RuleOptions
                            prefix="kind-channel"
                            title="Разрешённые каналы обращения"
                            hint="Существующие источники сохраняют уже выбранные значения; правило действует на новый выбор."
                            items={channels}
                            selected={form.data.channel_ids}
                            error={errors.channel_ids}
                            onChange={(ids) => form.setData('channel_ids', ids)}
                        />
                    </>
                )}
                {item?.code && (
                    <TechnicalCode id="reference-code" code={item.code} />
                )}
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    onClick={requestClose}
                    disabled={form.processing}
                >
                    Отмена
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    {item ? 'Сохранить' : 'Создать'}
                </Button>
            </DialogFooter>
        </form>
    );
}
