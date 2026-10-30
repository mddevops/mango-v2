import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';
import SourceController from '@/actions/App/Http/Controllers/Sources/SourceController';
import FormField from '@/components/form-field';
import { FormAlert } from '@/components/organization/form-alert';
import type { FormDialogControls } from '@/components/organization/form-dialog';
import { FormDialog } from '@/components/organization/form-dialog';
import { CheckboxOption } from '@/components/organization/form-section';
import { TechnicalCode } from '@/components/organization/technical-code';
import { Badge } from '@/components/reui/badge';
import { Button } from '@/components/ui/button';
import {
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { saveOptions } from '@/lib/submit';
import type {
    IntegrationLine,
    IntegrationLineType,
    MangoConnectionOption,
    ReferenceOption,
    SourceKindOption,
    SourceRow,
} from '@/types';

type Props = {
    open: boolean;
    source: SourceRow | null;
    showrooms: ReferenceOption[];
    kinds: SourceKindOption[];
    classifications: ReferenceOption[];
    channels: ReferenceOption[];
    agencies: ReferenceOption[];
    mangoConnections: MangoConnectionOption[];
    canManageConnections: boolean;
    onOpenChange: (open: boolean) => void;
};

export function SourceDialog({ open, onOpenChange, ...props }: Props) {
    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            className="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
        >
            {(controls) => <SourceForm {...props} controls={controls} />}
        </FormDialog>
    );
}

const NONE = 'none';

const LINE_TYPES: { value: IntegrationLineType; label: string }[] = [
    { value: 'phone', label: 'Телефон' },
    { value: 'sip', label: 'SIP' },
];

function optionLabel(item: ReferenceOption, disabledLabel: string) {
    return item.active ? item.name : `${item.name} (${disabledLabel})`;
}
function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h3 className="text-sm font-semibold">{title}</h3>
                {description && (
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

function SourceForm({
    source,
    showrooms,
    kinds,
    classifications,
    channels,
    agencies,
    mangoConnections,
    canManageConnections,
    controls,
}: Omit<Props, 'open' | 'onOpenChange'> & {
    controls: FormDialogControls;
}) {
    const { setBusy, setDirty, requestClose, close } = controls;
    const [failure, setFailure] = useState<string | null>(null);

    const showroomChoices = showrooms.filter(
        (item) => item.active || item.id === source?.showroom.id,
    );
    const kindChoices = kinds.filter(
        (item) => item.active || item.id === source?.kind.id,
    );

    const form = useForm({
        name: source?.name ?? '',
        showroom: source
            ? String(source.showroom.id)
            : showroomChoices.length === 1
              ? String(showroomChoices[0].id)
              : '',
        kind: source ? String(source.kind.id) : '',
        classification: source?.classification
            ? String(source.classification.id)
            : NONE,
        agency: source?.agency ? String(source.agency.id) : NONE,
        url: source?.url ?? '',
        note: source?.note ?? '',
        channel_ids: source?.channels.map((channel) => channel.id) ?? [],
        site_intake: source?.site_intake?.active ?? false,
        mango_connection: source?.mango
            ? String(source.mango.connection.id)
            : NONE,
        mango_lines: source?.mango?.lines ?? ([] as IntegrationLine[]),
        lock_version: source?.lock_version ?? 0,
    });

    useEffect(() => setDirty(form.isDirty), [form.isDirty, setDirty]);

    const kind = kinds.find((item) => String(item.id) === form.data.kind);
    const sameKind = source !== null && kind?.id === source.kind.id;

    const classificationChoices = useMemo(
        () =>
            classifications.filter(
                (item) =>
                    (sameKind && item.id === source?.classification?.id) ||
                    (item.active &&
                        (kind?.classification_ids.includes(item.id) ?? false)),
            ),
        [classifications, kind, sameKind, source],
    );

    const channelChoices = useMemo(
        () =>
            channels.filter(
                (item) =>
                    (sameKind &&
                        source?.channels.some(
                            (channel) => channel.id === item.id,
                        )) ||
                    (item.active &&
                        (kind?.channel_ids.includes(item.id) ?? false)),
            ),
        [channels, kind, sameKind, source],
    );

    const agencyChoices = agencies.filter(
        (item) => item.active || item.id === source?.agency?.id,
    );

    const connectionChoices = mangoConnections.filter(
        (item) =>
            String(item.id) === form.data.mango_connection ||
            (item.active &&
                item.showroom_ids.includes(Number(form.data.showroom))),
    );

    const changeKind = (value: string) => {
        const next = kinds.find((item) => String(item.id) === value);
        const keepsCurrent = source !== null && next?.id === source.kind.id;
        const allowedChannels = channels
            .filter(
                (item) =>
                    (keepsCurrent &&
                        source.channels.some(
                            (channel) => channel.id === item.id,
                        )) ||
                    (item.active && next?.channel_ids.includes(item.id)),
            )
            .map((item) => item.id);
        const classificationId = Number(form.data.classification);
        const classificationAllowed =
            form.data.classification === NONE ||
            (keepsCurrent && classificationId === source.classification?.id) ||
            (next?.classification_ids.includes(classificationId) ?? false);
        const keptChannels = form.data.channel_ids.filter((id) =>
            allowedChannels.includes(id),
        );

        form.setData((data) => ({
            ...data,
            kind: value,
            classification: classificationAllowed ? data.classification : NONE,
            channel_ids:
                keptChannels.length > 0 || source !== null
                    ? keptChannels
                    : allowedChannels,
        }));
    };

    const toggleChannel = (id: number, checked: boolean) =>
        form.setData(
            'channel_ids',
            checked
                ? [...form.data.channel_ids, id]
                : form.data.channel_ids.filter((item) => item !== id),
        );

    const setLine = (index: number, line: Partial<IntegrationLine>) =>
        form.setData(
            'mango_lines',
            form.data.mango_lines.map((item, position) =>
                position === index ? { ...item, ...line } : item,
            ),
        );

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
            showroom_id: data.showroom === '' ? null : Number(data.showroom),
            source_kind_id: data.kind === '' ? null : Number(data.kind),
            source_classification_id:
                data.classification === NONE
                    ? null
                    : Number(data.classification),
            agency_id: data.agency === NONE ? null : Number(data.agency),
            url: data.url.trim() === '' ? null : data.url.trim(),
            note: data.note.trim() === '' ? null : data.note,
            channel_ids: data.channel_ids,
            ...(canManageConnections
                ? {
                      connections: {
                          site_intake: data.site_intake,
                          mango_connection_id:
                              data.mango_connection === NONE
                                  ? null
                                  : Number(data.mango_connection),
                          mango_lines:
                              data.mango_connection === NONE
                                  ? []
                                  : data.mango_lines,
                      },
                  }
                : {}),
            ...(source ? { lock_version: data.lock_version } : {}),
        }));

        if (source) {
            form.put(SourceController.update.url(source.id), options);
        } else {
            form.post(SourceController.store.url(), options);
        }
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
            <DialogHeader>
                <DialogTitle>
                    {source ? 'Изменить источник' : 'Новый источник'}
                </DialogTitle>
                <DialogDescription>
                    Источник относится к одному салону. Вид источника — это
                    справочное значение, а не способ подключения.
                </DialogDescription>
            </DialogHeader>

            <FormAlert conflict={form.errors.lock_version} failure={failure} />

            <Section title="Основные данные">
                <FormField label="Название" error={form.errors.name}>
                    <Input
                        id="source-name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        required
                        maxLength={255}
                    />
                </FormField>
                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField label="Салон" error={errors.showroom_id}>
                        <Select
                            value={form.data.showroom}
                            onValueChange={(value) =>
                                form.setData('showroom', value)
                            }
                        >
                            <SelectTrigger
                                id="source-showroom"
                                className="w-full"
                            >
                                <SelectValue placeholder="Выберите салон" />
                            </SelectTrigger>
                            <SelectContent
                                position="popper"
                                className="max-h-72"
                            >
                                <SelectGroup>
                                    {showroomChoices.map((item) => (
                                        <SelectItem
                                            key={item.id}
                                            value={String(item.id)}
                                        >
                                            {optionLabel(item, 'отключён')}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        label="Вид источника"
                        error={errors.source_kind_id}
                    >
                        <Select
                            value={form.data.kind}
                            onValueChange={changeKind}
                        >
                            <SelectTrigger id="source-kind" className="w-full">
                                <SelectValue placeholder="Выберите вид" />
                            </SelectTrigger>
                            <SelectContent
                                position="popper"
                                className="max-h-72"
                            >
                                <SelectGroup>
                                    {kindChoices.map((item) => (
                                        <SelectItem
                                            key={item.id}
                                            value={String(item.id)}
                                        >
                                            {optionLabel(item, 'отключён')}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        label="Классификация"
                        description="Необязательно."
                        error={errors.source_classification_id}
                    >
                        <Select
                            value={form.data.classification}
                            onValueChange={(value) =>
                                form.setData('classification', value)
                            }
                        >
                            <SelectTrigger
                                id="source-classification"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent
                                position="popper"
                                className="max-h-72"
                            >
                                <SelectGroup>
                                    <SelectItem value={NONE}>
                                        Без классификации
                                    </SelectItem>
                                    {classificationChoices.map((item) => (
                                        <SelectItem
                                            key={item.id}
                                            value={String(item.id)}
                                        >
                                            {optionLabel(item, 'отключена')}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField label="Агентство" error={errors.agency_id}>
                        <Select
                            value={form.data.agency}
                            onValueChange={(value) =>
                                form.setData('agency', value)
                            }
                        >
                            <SelectTrigger
                                id="source-agency"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent
                                position="popper"
                                className="max-h-72"
                            >
                                <SelectGroup>
                                    <SelectItem value={NONE}>
                                        Без агентства
                                    </SelectItem>
                                    {agencyChoices.map((item) => (
                                        <SelectItem
                                            key={item.id}
                                            value={String(item.id)}
                                        >
                                            {optionLabel(item, 'отключено')}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </FormField>
                </div>
                <FormField
                    label="Адрес"
                    description="Необязательно. Только http:// или https:// без логина и пароля."
                    error={form.errors.url}
                >
                    <Input
                        id="source-url"
                        type="url"
                        inputMode="url"
                        value={form.data.url}
                        onChange={(event) =>
                            form.setData('url', event.target.value)
                        }
                        placeholder="https://example.ru"
                        maxLength={2048}
                    />
                </FormField>
            </Section>

            <Separator />

            <Section
                title="Каналы обращения"
                description="Как клиент может обратиться через этот источник. Список зависит от вида источника."
            >
                {kind ? (
                    channelChoices.length > 0 ? (
                        <div className="grid gap-2 sm:grid-cols-2">
                            {channelChoices.map((item) => (
                                <CheckboxOption
                                    key={item.id}
                                    id={`source-channel-${item.id}`}
                                    checked={form.data.channel_ids.includes(
                                        item.id,
                                    )}
                                    onChange={(checked) =>
                                        toggleChannel(item.id, checked)
                                    }
                                    label={item.name}
                                    aside={
                                        !item.active && (
                                            <Badge variant="warning-light">
                                                Отключён
                                            </Badge>
                                        )
                                    }
                                />
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Для этого вида не разрешено ни одного канала.
                        </p>
                    )
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Сначала выберите вид источника.
                    </p>
                )}
                {errors.channel_ids && (
                    <p className="text-sm text-destructive">
                        {errors.channel_ids}
                    </p>
                )}
            </Section>

            <Separator />

            <Section
                title="Подключения"
                description="Приём через API сайта работает при включённом приёме и выпущенном ключе. Настройка Mango пока только сохраняется — её состояние «Не проверено»."
            >
                {canManageConnections ? (
                    <div className="flex flex-col gap-4">
                        <CheckboxOption
                            id="source-site-intake"
                            checked={form.data.site_intake}
                            onChange={(checked) =>
                                form.setData('site_intake', checked)
                            }
                            label="Приём заявок с сайта"
                            hint="Сервер сайта отправляет обратные звонки в общий API с source_id этого источника. Адрес приёма, ключ и журнал обращений — в действии «API сайта» в списке источников."
                        />
                        <FormField
                            label="Mango VPBX"
                            description="Учётная запись АТС настраивается в разделе «Подключения» и может обслуживать несколько источников."
                            error={errors['connections.mango_connection_id']}
                        >
                            <Select
                                value={form.data.mango_connection}
                                onValueChange={(value) =>
                                    form.setData('mango_connection', value)
                                }
                            >
                                <SelectTrigger
                                    id="source-mango"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent
                                    position="popper"
                                    className="max-h-72"
                                >
                                    <SelectGroup>
                                        <SelectItem value={NONE}>
                                            Не привязан
                                        </SelectItem>
                                        {connectionChoices.map((item) => (
                                            <SelectItem
                                                key={item.id}
                                                value={String(item.id)}
                                            >
                                                {optionLabel(item, 'отключено')}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </FormField>
                        {form.data.mango_connection !== NONE && (
                            <div className="flex flex-col gap-3">
                                <span className="text-sm font-medium">
                                    Линии источника
                                </span>
                                {form.data.mango_lines.length === 0 && (
                                    <p className="text-xs text-muted-foreground">
                                        Добавьте номера или SIP-адреса, звонки
                                        на которые относятся к этому источнику.
                                    </p>
                                )}
                                {form.data.mango_lines.map((line, index) => (
                                    <div
                                        key={index}
                                        className="flex flex-col gap-1"
                                    >
                                        <div className="flex items-start gap-2">
                                            <Select
                                                value={line.type}
                                                onValueChange={(value) =>
                                                    setLine(index, {
                                                        type: value as IntegrationLineType,
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    className="w-32 shrink-0"
                                                    aria-label="Тип линии"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent position="popper">
                                                    <SelectGroup>
                                                        {LINE_TYPES.map(
                                                            (type) => (
                                                                <SelectItem
                                                                    key={
                                                                        type.value
                                                                    }
                                                                    value={
                                                                        type.value
                                                                    }
                                                                >
                                                                    {type.label}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectGroup>
                                                </SelectContent>
                                            </Select>
                                            <Input
                                                id={`source-line-${index}`}
                                                aria-label="Номер или SIP-адрес"
                                                aria-invalid={
                                                    Boolean(
                                                        errors[
                                                            `connections.mango_lines.${index}.value`
                                                        ],
                                                    ) || undefined
                                                }
                                                value={line.value}
                                                onChange={(event) =>
                                                    setLine(index, {
                                                        value: event.target
                                                            .value,
                                                    })
                                                }
                                                placeholder={
                                                    line.type === 'sip'
                                                        ? 'user@domain.mangosip.ru'
                                                        : '+7 999 123-45-67'
                                                }
                                                maxLength={100}
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label="Удалить линию"
                                                onClick={() =>
                                                    form.setData(
                                                        'mango_lines',
                                                        form.data.mango_lines.filter(
                                                            (_, position) =>
                                                                position !==
                                                                index,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                        {errors[
                                            `connections.mango_lines.${index}.value`
                                        ] && (
                                            <p className="text-sm text-destructive">
                                                {
                                                    errors[
                                                        `connections.mango_lines.${index}.value`
                                                    ]
                                                }
                                            </p>
                                        )}
                                    </div>
                                ))}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="self-start"
                                    onClick={() =>
                                        form.setData('mango_lines', [
                                            ...form.data.mango_lines,
                                            { type: 'phone', value: '' },
                                        ])
                                    }
                                >
                                    <Plus />
                                    Добавить линию
                                </Button>
                                {errors['connections.mango_lines'] && (
                                    <p className="text-sm text-destructive">
                                        {errors['connections.mango_lines']}
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                ) : (
                    <ConnectionSummary source={source} />
                )}
            </Section>

            <Separator />

            <Section title="Дополнительные сведения">
                <FormField label="Примечание" error={form.errors.note}>
                    <Textarea
                        id="source-note"
                        value={form.data.note}
                        onChange={(event) =>
                            form.setData('note', event.target.value)
                        }
                        maxLength={2000}
                        rows={3}
                    />
                </FormField>
                {source?.legacy_classification && (
                    <p className="text-xs text-muted-foreground">
                        Тип сайта до перехода на источники:{' '}
                        {source.legacy_classification}
                    </p>
                )}
                {source && (
                    <TechnicalCode id="source-code" code={source.code} />
                )}
            </Section>

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
                    {source ? 'Сохранить' : 'Создать'}
                </Button>
            </DialogFooter>
        </form>
    );
}

function ConnectionSummary({ source }: { source: SourceRow | null }) {
    if (!source?.site_intake?.active && !source?.mango) {
        return (
            <p className="text-sm text-muted-foreground">
                Подключения не настроены. Их настраивает администратор.
            </p>
        );
    }

    return (
        <ul className="flex flex-col gap-2 text-sm">
            {source.site_intake?.active && (
                <li className="flex flex-wrap items-center gap-2">
                    Приём заявок с сайта
                    <Badge variant="info-light">Включён</Badge>
                </li>
            )}
            {source.mango && (
                <li className="flex flex-col gap-1">
                    <span className="flex flex-wrap items-center gap-2">
                        Mango VPBX: {source.mango.connection.name}
                        <Badge variant="warning-light">Не проверено</Badge>
                    </span>
                    {source.mango.lines.length > 0 && (
                        <span className="text-xs text-muted-foreground">
                            {source.mango.lines
                                .map((line) =>
                                    line.type === 'sip'
                                        ? `SIP ${line.value}`
                                        : line.value,
                                )
                                .join(', ')}
                        </span>
                    )}
                </li>
            )}
        </ul>
    );
}
