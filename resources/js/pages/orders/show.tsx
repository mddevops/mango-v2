import { Head, Link, router, useForm, useHttp } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    History,
    MessageSquare,
    Building2,
    Phone,
    RotateCcw,
    RotateCw,
    Send,
    TriangleAlert,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import {
    DistributeButton,
    TakeButton,
} from '@/components/distribution/distribute-button';
import FormField from '@/components/form-field';
import {
    OrderPhonesField,
    phonesPayload,
    toPhoneRows,
} from '@/components/orders/order-phones-field';
import { MissedCallsSection } from '@/components/orders/missed-calls-section';
import { OrderRepeatBadge } from '@/components/orders/order-repeat-badge';
import {
    OrderStatusBadge,
    OrderStatusDot,
} from '@/components/orders/order-status-badge';
import { RelatedOrdersTable } from '@/components/orders/related-orders-table';
import { TransferDialog } from '@/components/orders/transfer-dialog';
import { FormAlert } from '@/components/organization/form-alert';
import { FormSection } from '@/components/organization/form-section';
import { PageBody, PageHeader } from '@/components/organization/page-header';
import {
    Alert,
    AlertAction,
    AlertDescription,
    AlertTitle,
} from '@/components/reui/alert';
import { Badge } from '@/components/reui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    formatBusinessDate,
    formatLocal,
    formatMoment,
} from '@/lib/order-format';
import { useCoalesced, useRealtimeSignal } from '@/hooks/use-realtime-signal';
import { saveOptions } from '@/lib/submit';
import { index as ordersIndex, show as ordersShow } from '@/routes/orders';
import type {
    OrderCall,
    OrderCallRecording,
    OrderCard,
    OrderComment,
    OrderEditOptions,
    OrderHistoryEntry,
    OrderMissedCall,
    OrderSiteRequest,
    OrderTransferInfo,
    Paginated,
    ReferenceOption,
    RelatedOrder,
} from '@/types';

type Props = {
    order: OrderCard;
    options: OrderEditOptions | null;
    comments?: Paginated<OrderComment>;
    previous?: RelatedOrder[];
    crossShowroom?: RelatedOrder[];
    history?: Paginated<OrderHistoryEntry>;
    calls?: Paginated<OrderCall>;
    missedCalls: OrderMissedCall[];
    can: {
        update: boolean;
        assign: boolean;
        comment: boolean;
        history: boolean;
        recordings: boolean;
        previous: boolean;
        crossShowroom: boolean;
        distribute: boolean;
        take: boolean;
        transfer: boolean;
    };
    transferShowrooms: ReferenceOption[];
};

const NONE = 'none';

/** Reloads after a save: the card itself, never a new «view» of it. */
const SAVED_PROPS = ['order', 'options', 'previous', 'can', 'transferShowrooms'];

function TransferFact({
    label,
    transfer,
}: {
    label: string;
    transfer: OrderTransferInfo;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Fact label={label}>
                {transfer.showroom}
                {transfer.order_id !== null && (
                    <>
                        {' · '}
                        <Link
                            href={OrderController.show.url(transfer.order_id)}
                            className="underline underline-offset-4"
                        >
                            заявка №{transfer.order_id}
                        </Link>
                    </>
                )}
            </Fact>
            <Fact label="Передал">
                {transfer.user} · {formatMoment(transfer.created_at)}
            </Fact>
            {transfer.comment && (
                <Fact label="Комментарий передачи">
                    <span className="whitespace-pre-wrap">
                        {transfer.comment}
                    </span>
                </Fact>
            )}
        </div>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5">
            <span className="text-xs text-muted-foreground">{label}</span>
            <span className="text-sm">{children}</span>
        </div>
    );
}

/** Only what the site actually sent; ids are shown as ids, the CRM has no vehicle catalogue. */
function SiteRequestSection({ request }: { request: OrderSiteRequest }) {
    const utm = Object.entries(request.utm);

    if (
        !request.url &&
        !request.form &&
        utm.length === 0 &&
        request.viewed_vehicles.length === 0
    ) {
        return null;
    }

    return (
        <FormSection title="Данные с сайта">
            <div className="flex flex-col gap-4">
                {(request.url || request.form) && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {request.url && (
                            <Fact label="Точка входа">
                                <a
                                    href={request.url}
                                    target="_blank"
                                    rel="noopener noreferrer nofollow"
                                    className="break-all underline underline-offset-4"
                                >
                                    {request.url}
                                </a>
                            </Fact>
                        )}
                        {request.form && (
                            <Fact label="Форма">{request.form}</Fact>
                        )}
                    </div>
                )}

                {request.viewed_vehicles.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <span className="text-xs text-muted-foreground">
                            Просмотренные автомобили
                        </span>
                        {request.viewed_vehicles.map((group) => (
                            <div
                                key={group.section}
                                className="flex flex-wrap items-center gap-1"
                            >
                                <span className="me-1 text-sm">
                                    {group.label}:
                                </span>
                                {group.ids.map((id) => (
                                    <Badge
                                        key={id}
                                        variant="outline"
                                        className="tabular-nums"
                                    >
                                        {id}
                                    </Badge>
                                ))}
                            </div>
                        ))}
                    </div>
                )}

                {utm.length > 0 && (
                    <Collapsible>
                        <CollapsibleTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="group -ms-2 self-start"
                            >
                                <ChevronRight className="transition-transform group-data-[state=open]:rotate-90" />
                                Рекламные метки ({utm.length})
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div className="grid gap-4 pt-2 sm:grid-cols-2 lg:grid-cols-3">
                                {utm.map(([key, value]) => (
                                    <Fact key={key} label={key}>
                                        <span className="break-all">
                                            {value}
                                        </span>
                                    </Fact>
                                ))}
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                )}
            </div>
        </FormSection>
    );
}

function inactive(name: string, active: boolean): string {
    return active ? name : `${name} (отключено)`;
}

function SectionPager<T>({
    page,
    onPage,
}: {
    page: Paginated<T>;
    onPage: (page: number) => void;
}) {
    if (page.meta.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-end gap-2 text-xs text-muted-foreground">
            <span>
                Страница {page.meta.current_page} из {page.meta.last_page}
            </span>
            <Button
                type="button"
                variant="outline"
                size="icon"
                aria-label="Предыдущая страница"
                disabled={page.meta.current_page <= 1}
                onClick={() => onPage(page.meta.current_page - 1)}
            >
                <ChevronLeft />
            </Button>
            <Button
                type="button"
                variant="outline"
                size="icon"
                aria-label="Следующая страница"
                disabled={page.meta.current_page >= page.meta.last_page}
                onClick={() => onPage(page.meta.current_page + 1)}
            >
                <ChevronRight />
            </Button>
        </div>
    );
}

function loadSection(prop: string, data: Record<string, number> = {}) {
    router.reload({ only: [prop], data });
}

function OrderEditForm({
    order,
    options,
    canAssign,
    onEditing,
}: {
    order: OrderCard;
    options: OrderEditOptions;
    canAssign: boolean;
    onEditing: (editing: boolean) => void;
}) {
    const [failure, setFailure] = useState<string | null>(null);

    const form = useForm({
        contact_channel_id: String(order.channel.id),
        contact_name: order.contact_name ?? '',
        phones: toPhoneRows(order.phones),
        car_interest: order.car_interest ?? '',
        order_status_id: String(order.status.id),
        callback_at: order.callback_at ?? '',
        arrival_at: order.arrival_at ?? '',
        reason_id: order.reason ? String(order.reason.id) : '',
        reason_comment: order.reason_comment ?? '',
        responsible_id: order.responsible
            ? String(order.responsible.id)
            : NONE,
        lock_version: order.lock_version,
    });

    const editing = form.isDirty || form.processing;

    useEffect(() => {
        onEditing(editing);

        return () => onEditing(false);
    }, [editing, onEditing]);

    const errors = form.errors as Record<string, string | undefined>;
    const status = options.statuses.find(
        (item) => String(item.id) === form.data.order_status_id,
    );
    const reasonType = status?.required_reason ?? null;
    const reasons = reasonType ? options.reasons[reasonType] : [];
    const reason = reasons.find(
        (item) => String(item.id) === form.data.reason_id,
    );
    const showCallback = Boolean(
        status?.requires_callback_at || form.data.callback_at,
    );
    const showArrival = Boolean(
        status?.requires_arrival_at || form.data.arrival_at,
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        form.transform((data) => {
            const payload: Record<string, unknown> = {
                contact_channel_id: Number(data.contact_channel_id),
                contact_name: data.contact_name || null,
                phones: phonesPayload(data.phones),
                car_interest: data.car_interest || null,
                order_status_id: Number(data.order_status_id),
                callback_at: data.callback_at || null,
                arrival_at: data.arrival_at || null,
                reason_id: data.reason_id ? Number(data.reason_id) : null,
                reason_comment: data.reason_comment || null,
                lock_version: data.lock_version,
            };

            if (canAssign) {
                payload.responsible_id =
                    data.responsible_id === NONE
                        ? null
                        : Number(data.responsible_id);
            }

            return payload;
        });

        form.put(
            OrderController.update.url(order.id),
            saveOptions(setFailure, { only: SAVED_PROPS }),
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <FormAlert conflict={errors.lock_version} failure={failure} />

            <FormSection
                title="Обработка"
                description={`Даты указываются по времени салона (${order.showroom.timezone}).`}
            >
                <div className="grid gap-4 md:grid-cols-2">
                    <FormField label="Статус" error={errors.order_status_id}>
                        <Select
                            value={form.data.order_status_id}
                            onValueChange={(value) =>
                                form.setData('order_status_id', value)
                            }
                        >
                            <SelectTrigger
                                id="order-status"
                                className="w-full justify-between"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent
                                position="popper"
                                className="min-w-48"
                            >
                                <SelectGroup>
                                    {options.statuses.map((item) => (
                                        <SelectItem
                                            key={item.id}
                                            value={String(item.id)}
                                        >
                                            <OrderStatusDot color={item.color}>
                                                {inactive(
                                                    item.name,
                                                    item.active,
                                                )}
                                            </OrderStatusDot>
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </FormField>

                    {canAssign && options.responsibles ? (
                        <FormField
                            label="Ответственный"
                            error={errors.responsible_id}
                        >
                            <Select
                                value={form.data.responsible_id}
                                onValueChange={(value) =>
                                    form.setData('responsible_id', value)
                                }
                            >
                                <SelectTrigger
                                    id="order-responsible"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent position="popper">
                                    <SelectGroup>
                                        <SelectItem value={NONE}>
                                            Не назначен
                                        </SelectItem>
                                        {options.responsibles.map((user) => (
                                            <SelectItem
                                                key={user.id}
                                                value={String(user.id)}
                                            >
                                                {inactive(
                                                    user.name,
                                                    user.active,
                                                )}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </FormField>
                    ) : (
                        <Fact label="Ответственный">
                            {order.responsible
                                ? inactive(
                                      order.responsible.name,
                                      order.responsible.active,
                                  )
                                : 'не назначен'}
                        </Fact>
                    )}

                    {showCallback && (
                        <FormField
                            label="Перезвонить"
                            error={errors.callback_at}
                        >
                            <Input
                                id="order-callback-at"
                                type="datetime-local"
                                value={form.data.callback_at}
                                onChange={(event) =>
                                    form.setData(
                                        'callback_at',
                                        event.target.value,
                                    )
                                }
                                required={status?.requires_callback_at}
                            />
                        </FormField>
                    )}

                    {showArrival && (
                        <FormField
                            label="Дата приезда"
                            error={errors.arrival_at}
                        >
                            <Input
                                id="order-arrival-at"
                                type="datetime-local"
                                value={form.data.arrival_at}
                                onChange={(event) =>
                                    form.setData(
                                        'arrival_at',
                                        event.target.value,
                                    )
                                }
                                required={status?.requires_arrival_at}
                            />
                        </FormField>
                    )}

                    {reasonType && (
                        <>
                            <FormField
                                label={options.reasonTitles[reasonType]}
                                error={errors.reason_id}
                            >
                                <Select
                                    value={form.data.reason_id}
                                    onValueChange={(value) =>
                                        form.setData('reason_id', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="order-reason"
                                        className="w-full"
                                        aria-required
                                    >
                                        <SelectValue placeholder="Выберите причину" />
                                    </SelectTrigger>
                                    <SelectContent position="popper">
                                        <SelectGroup>
                                            {reasons.map((item) => (
                                                <SelectItem
                                                    key={item.id}
                                                    value={String(item.id)}
                                                >
                                                    {inactive(
                                                        item.name,
                                                        item.active,
                                                    )}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                label="Пояснение к причине"
                                error={errors.reason_comment}
                            >
                                <Textarea
                                    id="order-reason-comment"
                                    value={form.data.reason_comment}
                                    onChange={(event) =>
                                        form.setData(
                                            'reason_comment',
                                            event.target.value,
                                        )
                                    }
                                    required={reason?.comment_required}
                                    maxLength={2000}
                                    rows={2}
                                />
                            </FormField>
                        </>
                    )}
                </div>
            </FormSection>

            <FormSection title="Контакт">
                <div className="grid gap-4 md:grid-cols-2">
                    <FormField label="Имя" error={errors.contact_name}>
                        <Input
                            id="order-contact-name"
                            value={form.data.contact_name}
                            onChange={(event) =>
                                form.setData('contact_name', event.target.value)
                            }
                            maxLength={150}
                            autoComplete="off"
                        />
                    </FormField>
                    <FormField
                        label="Тип заявки"
                        error={errors.contact_channel_id}
                    >
                        <Select
                            value={form.data.contact_channel_id}
                            onValueChange={(value) =>
                                form.setData('contact_channel_id', value)
                            }
                        >
                            <SelectTrigger
                                id="order-channel"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent position="popper">
                                <SelectGroup>
                                    {options.channels.map((channel) => (
                                        <SelectItem
                                            key={channel.id}
                                            value={String(channel.id)}
                                        >
                                            {inactive(
                                                channel.name,
                                                channel.active,
                                            )}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </FormField>
                    <div className="md:col-span-2">
                        <OrderPhonesField
                            phones={form.data.phones}
                            maxExtra={options.maxExtraPhones}
                            errors={errors}
                            onChange={(phones) =>
                                form.setData('phones', phones)
                            }
                        />
                    </div>
                    <FormField
                        label="Интерес к автомобилю"
                        error={errors.car_interest}
                    >
                        <Textarea
                            id="order-car-interest"
                            value={form.data.car_interest}
                            onChange={(event) =>
                                form.setData('car_interest', event.target.value)
                            }
                            maxLength={2000}
                            rows={3}
                        />
                    </FormField>
                </div>
            </FormSection>

            <div className="flex flex-wrap justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    disabled={!form.isDirty || form.processing}
                    onClick={() => form.reset()}
                >
                    Отменить изменения
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    Сохранить
                </Button>
            </div>
        </form>
    );
}

function OrderReadOnly({ order }: { order: OrderCard }) {
    return (
        <FormSection title="Контакт и обработка">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Fact label="Имя">{order.contact_name ?? '—'}</Fact>
                <Fact label="Телефоны">
                    {order.phones
                        .map((phone) =>
                            phone.name
                                ? `${phone.formatted} (${phone.name})`
                                : phone.formatted,
                        )
                        .join(', ')}
                </Fact>
                <Fact label="Тип заявки">{order.channel.name}</Fact>
                <Fact label="Ответственный">
                    {order.responsible?.name ?? 'не назначен'}
                </Fact>
                <Fact label="Перезвонить">{formatLocal(order.callback_at)}</Fact>
                <Fact label="Дата приезда">{formatLocal(order.arrival_at)}</Fact>
                {order.reason && (
                    <Fact label="Причина">
                        {order.reason.name}
                        {order.reason_comment && ` — ${order.reason_comment}`}
                    </Fact>
                )}
                <Fact label="Интерес к автомобилю">
                    {order.car_interest ?? '—'}
                </Fact>
            </div>
        </FormSection>
    );
}

function CommentsSection({
    order,
    comments,
    canComment,
}: {
    order: OrderCard;
    comments?: Paginated<OrderComment>;
    canComment: boolean;
}) {
    const [failure, setFailure] = useState<string | null>(null);
    const form = useForm({ body: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        form.post(
            OrderController.storeComment.url(order.id),
            saveOptions(setFailure, {
                only: ['comments'],
                onSuccess: () => form.reset(),
            }),
        );
    };

    return (
        <FormSection
            title="Комментарии"
            actions={
                comments === undefined && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => loadSection('comments')}
                    >
                        <MessageSquare />
                        Показать комментарии
                    </Button>
                )
            }
        >
            <div className="flex flex-col gap-4">
                {canComment && (
                    <form
                        onSubmit={submit}
                        className="flex flex-col gap-2"
                        noValidate
                    >
                        <FormAlert failure={failure} />
                        <FormField
                            label="Новый комментарий"
                            error={form.errors.body}
                        >
                            <Textarea
                                id="order-comment"
                                value={form.data.body}
                                onChange={(event) =>
                                    form.setData('body', event.target.value)
                                }
                                maxLength={5000}
                                rows={3}
                            />
                        </FormField>
                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={
                                    form.processing ||
                                    form.data.body.trim() === ''
                                }
                            >
                                {form.processing && <Spinner />}
                                Добавить комментарий
                            </Button>
                        </div>
                    </form>
                )}

                {comments && (
                    <>
                        {comments.data.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Комментариев пока нет.
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-3">
                                {comments.data.map((comment) => (
                                    <li
                                        key={comment.id}
                                        className="flex flex-col gap-1 rounded-lg border px-3 py-2"
                                    >
                                        <span className="text-xs text-muted-foreground">
                                            {comment.author} ·{' '}
                                            {formatMoment(comment.created_at)}
                                        </span>
                                        <p className="text-sm whitespace-pre-wrap">
                                            {comment.body}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <SectionPager
                            page={comments}
                            onPage={(page) =>
                                loadSection('comments', {
                                    comments_page: page,
                                })
                            }
                        />
                    </>
                )}
            </div>
        </FormSection>
    );
}

function HistorySection({
    history,
}: {
    history?: Paginated<OrderHistoryEntry>;
}) {
    return (
        <FormSection
            title="История"
            description="Просмотры карточки и изменения заявки."
            actions={
                history === undefined && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => loadSection('history')}
                    >
                        <History />
                        Показать историю
                    </Button>
                )
            }
        >
            {history ? (
                <div className="flex flex-col gap-3">
                    <ul className="flex flex-col gap-3">
                        {history.data.map((entry) => (
                            <li
                                key={entry.id}
                                className="flex flex-col gap-1 rounded-lg border px-3 py-2"
                            >
                                <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                    {formatMoment(entry.created_at)} ·{' '}
                                    {entry.actor}
                                    {entry.system && (
                                        <Badge variant="info-light">
                                            Система
                                        </Badge>
                                    )}
                                </span>
                                <span className="text-sm font-medium">
                                    {entry.description}
                                </span>
                                {entry.changes.length > 0 && (
                                    <dl className="grid gap-x-3 gap-y-1 text-xs sm:grid-cols-[minmax(0,10rem)_1fr]">
                                        {entry.changes.map((change) => (
                                            <div
                                                key={change.field}
                                                className="contents"
                                            >
                                                <dt className="text-muted-foreground">
                                                    {change.field}
                                                </dt>
                                                <dd className="break-words">
                                                    {change.diff
                                                        ? `${change.before ?? '—'} → ${change.after ?? '—'}`
                                                        : (change.after ?? '—')}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                )}
                            </li>
                        ))}
                    </ul>
                    <SectionPager
                        page={history}
                        onPage={(page) =>
                            loadSection('history', { history_page: page })
                        }
                    />
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">
                    История загружается по запросу.
                </p>
            )}
        </FormSection>
    );
}

function duration(seconds: number): string {
    const minutes = Math.floor(seconds / 60);

    return `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
}

const RECORDING_BADGES: Record<
    OrderCallRecording['state'],
    'secondary' | 'info-light' | 'warning-light'
> = {
    pending: 'secondary',
    recorded: 'info-light',
    unavailable: 'warning-light',
};

/** A v4 UUID also outside a secure context, where `crypto.randomUUID` is missing. */
function playbackId(): string {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * A plain player of the protected route that streams the recording from Mango. The start
 * of a playback is reported once; pause, seeking, the browser's range requests and a
 * retry belong to the same playback, a new one starts after the end. A failure is checked
 * once with HEAD (no audio is transferred) to tell «not available yet» from other errors;
 * nothing is repeated without the user.
 */
function RecordingPlayer({ recording }: { recording: OrderCallRecording }) {
    const http = useHttp({ playback: '' });
    const playback = useRef<string | null>(null);
    const audio = useRef<HTMLAudioElement>(null);
    const [error, setError] = useState<string | null>(null);

    const started = () => {
        if (recording.play_url === null || playback.current !== null) {
            return;
        }

        const id = playbackId();
        playback.current = id;
        http.transform(() => ({ playback: id }));
        void http.post(recording.play_url).catch(() => undefined);
    };

    const failed = () => {
        if (recording.audio_url === null) {
            return;
        }

        setError('Не удалось воспроизвести');
        void fetch(recording.audio_url, {
            method: 'HEAD',
            credentials: 'same-origin',
        })
            .then((response) => {
                if (response.status === 404) {
                    setError('Запись пока недоступна');
                }
            })
            .catch(() => undefined);
    };

    const retry = () => {
        setError(null);
        audio.current?.load();
        void audio.current?.play().catch(() => undefined);
    };

    return (
        <span className="flex flex-col gap-1">
            <audio
                ref={audio}
                controls
                controlsList="nodownload"
                preload="none"
                src={recording.audio_url ?? undefined}
                aria-label={recording.label}
                className="h-9 w-full max-w-md"
                onPlay={started}
                onError={failed}
                onEnded={() => {
                    playback.current = null;
                }}
            />
            {error !== null && (
                <span className="flex flex-wrap items-center gap-2 text-xs">
                    <span className="text-destructive">{error}</span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={retry}
                    >
                        <RotateCcw />
                        Повторить
                    </Button>
                </span>
            )}
        </span>
    );
}

function CallRecordings({ recordings }: { recordings: OrderCallRecording[] }) {
    if (recordings.length === 0) {
        return (
            <span className="text-xs text-muted-foreground">
                Сведений о записи от Mango нет
            </span>
        );
    }

    return (
        <ul className="flex flex-col gap-2">
            {recordings.map((recording) => (
                <li key={recording.id} className="flex flex-col gap-1">
                    <span className="flex flex-wrap items-center gap-2 text-xs">
                        {recording.label}
                        <Badge variant={RECORDING_BADGES[recording.state]}>
                            {recording.state_label}
                        </Badge>
                    </span>
                    {recording.state === 'recorded' &&
                        recording.audio_url !== null && (
                            <RecordingPlayer recording={recording} />
                        )}
                </li>
            ))}
        </ul>
    );
}

function CallsSection({ calls }: { calls?: Paginated<OrderCall> }) {
    return (
        <FormSection
            title="Звонки"
            description="Звонки Mango VPBX по этой заявке."
            actions={
                calls === undefined && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => loadSection('calls')}
                    >
                        <Phone />
                        Показать звонки
                    </Button>
                )
            }
        >
            {calls ? (
                calls.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Звонков по заявке нет.
                    </p>
                ) : (
                    <div className="flex flex-col gap-3">
                        <ul className="flex flex-col gap-3">
                            {calls.data.map((call) => (
                                <li
                                    key={call.id}
                                    className="flex flex-col gap-1 rounded-lg border px-3 py-2"
                                >
                                    <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        {call.started_at
                                            ? formatMoment(call.started_at)
                                            : 'Время начала неизвестно'}
                                        {call.ended_at &&
                                            ` — ${formatMoment(call.ended_at)}`}
                                        <Badge variant="secondary">
                                            {call.direction}
                                        </Badge>
                                        {call.missed && (
                                            <Badge variant="destructive-light">
                                                Пропущен
                                            </Badge>
                                        )}
                                    </span>
                                    <span className="text-sm">
                                        {call.answered
                                            ? `Ответил: ${call.answered}`
                                            : call.missed
                                              ? 'Никто не ответил'
                                              : 'Ответивший не определён'}
                                        {call.talk_seconds !== null &&
                                            ` · разговор ${duration(call.talk_seconds)}`}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {call.state}
                                        {call.source &&
                                            ` · источник: ${call.source}`}
                                        {call.line && ` · линия: ${call.line}`}
                                    </span>
                                    {call.legs.length > 0 && (
                                        <ul className="flex flex-col gap-0.5 text-xs">
                                            {call.legs.map((leg) => (
                                                <li key={leg.id}>
                                                    {leg.transferred
                                                        ? '↳ '
                                                        : ''}
                                                    {leg.employee ??
                                                        'Голосовое меню / очередь'}
                                                    {leg.connected_at &&
                                                        ` · соединён ${formatMoment(leg.connected_at)}`}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {call.recordings !== undefined && (
                                        <CallRecordings
                                            recordings={call.recordings}
                                        />
                                    )}
                                </li>
                            ))}
                        </ul>
                        <SectionPager
                            page={calls}
                            onPage={(page) =>
                                loadSection('calls', { calls_page: page })
                            }
                        />
                    </div>
                )
            ) : (
                <p className="text-sm text-muted-foreground">
                    Звонки загружаются по запросу.
                </p>
            )}
        </FormSection>
    );
}

export default function OrderShow({
    order,
    options,
    comments,
    previous,
    crossShowroom,
    history,
    calls,
    missedCalls,
    can,
    transferShowrooms,
}: Props) {
    const title = `Заявка №${order.id}`;
    const [transferOpen, setTransferOpen] = useState(false);
    const editing = useRef(false);
    const onEditing = useCallback((value: boolean) => {
        editing.current = value;
    }, []);
    const [outdatedAt, setOutdatedAt] = useState<number | null>(null);
    const outdated = outdatedAt === order.lock_version;
    const callsLoaded = calls !== undefined;
    const reloadCard = useCoalesced(() =>
        router.reload({ only: SAVED_PROPS }),
    );
    const reloadCalls = useCoalesced(() => router.reload({ only: ['calls'] }));
    const reloadMissed = useCoalesced(() =>
        router.reload({ only: ['missedCalls'] }),
    );

    const visible = async (): Promise<boolean> => {
        try {
            const response = await fetch(OrderController.access.url(order.id), {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                return true;
            }

            const result = (await response.json()) as { visible: boolean };

            if (!result.visible) {
                toast.warning(
                    `${title} больше вам недоступна: ответственный или права изменились.`,
                );
                router.visit(ordersIndex());
            }

            return result.visible;
        } catch {
            return true;
        }
    };

    useRealtimeSignal((signal) => {
        switch (signal.type) {
            case 'order.updated':
                if (signal.order_id !== order.id) {
                    return;
                }

                // A missed call coming or handled leaves the order's version as is.
                reloadMissed();

                if (signal.version <= order.lock_version) {
                    return;
                }

                if (editing.current) {
                    setOutdatedAt(order.lock_version);
                } else {
                    reloadCard();
                }

                return;
            case 'order.access-check':
                if (signal.order_id === order.id) {
                    void visible();
                }

                return;
            case 'call.updated':
                if (signal.order_id === order.id && callsLoaded) {
                    reloadCalls();
                }

                return;
            case 'resync':
                void visible().then((still) => {
                    if (!still) {
                        return;
                    }

                    if (!editing.current) {
                        reloadCard();
                    }

                    reloadMissed();

                    if (callsLoaded) {
                        reloadCalls();
                    }
                });

                return;
        }
    });

    return (
        <>
            <Head title={title} />
            <PageBody fluid>
                <PageHeader
                    title={title}
                    description={
                        <span className="flex flex-wrap items-center gap-2">
                            <OrderStatusBadge color={order.status.color}>
                                {order.status.name}
                            </OrderStatusBadge>
                            <OrderRepeatBadge count={order.repeat_number} />
                            <Badge variant="outline">{order.origin}</Badge>
                            {order.transfer_out && (
                                <Badge variant="info-light">
                                    Передано в {order.transfer_out.showroom}
                                </Badge>
                            )}
                            {order.transfer_in && (
                                <Badge variant="info-light">
                                    Получено из {order.transfer_in.showroom}
                                </Badge>
                            )}
                        </span>
                    }
                    actions={
                        (can.take || can.transfer) && (
                            <div className="flex flex-wrap gap-2">
                                {can.take && (
                                    <TakeButton
                                        orderId={order.id}
                                        only={SAVED_PROPS}
                                    />
                                )}
                                {can.transfer && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setTransferOpen(true)}
                                    >
                                        <Send />
                                        Передать в другой салон
                                    </Button>
                                )}
                            </div>
                        )
                    }
                />

                {(order.transfer_out || order.transfer_in) && (
                    <FormSection title="Передача между салонами">
                        <div className="flex flex-col gap-4">
                            {order.transfer_in && (
                                <TransferFact
                                    label="Получено из салона"
                                    transfer={order.transfer_in}
                                />
                            )}
                            {order.transfer_out && (
                                <TransferFact
                                    label="Передано в салон"
                                    transfer={order.transfer_out}
                                />
                            )}
                        </div>
                    </FormSection>
                )}

                <FormSection title="Основное">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Fact label="Салон">{order.showroom.name}</Fact>
                        <Fact label="Источник">
                            {inactive(order.source.name, order.source.active)}
                        </Fact>
                        <Fact label="Тип заявки">
                            {inactive(order.channel.name, order.channel.active)}
                        </Fact>
                        <Fact label="Поступила">
                            {formatMoment(order.received_at)}
                        </Fact>
                        <Fact label="Бизнес-дата">
                            {formatBusinessDate(order.business_date)}
                            <span className="text-xs text-muted-foreground">
                                {' '}
                                (
                                {order.business_day_rule.boundary
                                    ? `граница ${order.business_day_rule.boundary}`
                                    : 'календарный день'}
                                , {order.business_day_rule.timezone})
                            </span>
                        </Fact>
                        <Fact label="Создал">{order.creator ?? '—'}</Fact>
                        <Fact label="Изменена">
                            {formatMoment(order.updated_at)}
                        </Fact>
                    </div>
                </FormSection>

                <MissedCallsSection
                    orderId={order.id}
                    calls={missedCalls}
                    canHandle={can.update}
                />

                {order.site_request && (
                    <SiteRequestSection request={order.site_request} />
                )}

                {(order.distribution || can.distribute) && (
                    <FormSection
                        title="Распределение"
                        actions={
                            can.distribute && (
                                <DistributeButton
                                    orderId={order.id}
                                    label={
                                        order.distribution?.state === 'pending'
                                            ? 'Повторить распределение'
                                            : 'Отправить в распределение'
                                    }
                                    only={SAVED_PROPS}
                                />
                            )
                        }
                    >
                        {order.distribution ? (
                            <Fact label="Состояние">
                                {order.distribution.label}
                            </Fact>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Ответственный не назначен. Заявку можно передать
                                в распределение по графикам операторов.
                            </p>
                        )}
                    </FormSection>
                )}

                {outdated && (
                    <Alert variant="warning">
                        <TriangleAlert />
                        <AlertTitle>
                            Данные изменились, обновите карточку
                        </AlertTitle>
                        <AlertDescription>
                            Заявку изменили, пока вы её редактировали.
                            Несохранённые правки при обновлении будут потеряны.
                        </AlertDescription>
                        <AlertAction>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.reload({ only: SAVED_PROPS })
                                }
                            >
                                <RotateCw />
                                Обновить
                            </Button>
                        </AlertAction>
                    </Alert>
                )}

                {can.update && options ? (
                    <OrderEditForm
                        key={order.lock_version}
                        order={order}
                        options={options}
                        canAssign={can.assign}
                        onEditing={onEditing}
                    />
                ) : (
                    <OrderReadOnly order={order} />
                )}

                <CommentsSection
                    order={order}
                    comments={comments}
                    canComment={can.comment}
                />

                {previous && (
                    <FormSection
                        title="Предыдущие обращения в салоне"
                        actions={
                            can.crossShowroom &&
                            crossShowroom === undefined && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        loadSection('crossShowroom')
                                    }
                                >
                                    <Building2 />
                                    Обращения в других салонах
                                </Button>
                            )
                        }
                    >
                        <RelatedOrdersTable
                            rows={previous}
                            emptyMessage="Это первое обращение клиента в салон."
                        />
                    </FormSection>
                )}

                {crossShowroom && (
                    <FormSection
                        title="Обращения в других салонах"
                        description="Только салоны из вашей области доступа. Не влияет на счётчик повторов."
                    >
                        <RelatedOrdersTable
                            rows={crossShowroom}
                            showShowroom
                            emptyMessage="В других доступных салонах обращений нет."
                        />
                    </FormSection>
                )}

                {can.history && <CallsSection calls={calls} />}
                {can.history && <HistorySection history={history} />}
            </PageBody>

            <TransferDialog
                open={transferOpen}
                order={order}
                showrooms={transferShowrooms}
                only={SAVED_PROPS}
                onOpenChange={setTransferOpen}
            />
        </>
    );
}

OrderShow.layout = ({ order }: Props) => ({
    breadcrumbs: [
        { title: 'Заявки', href: ordersIndex() },
        { title: `Заявка №${order.id}`, href: ordersShow(order.id) },
    ],
});
