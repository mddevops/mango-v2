import { useForm, useHttp } from '@inertiajs/react';
import { SparklesIcon } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { Fragment, useEffect, useRef, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { DateTimePicker } from '@/components/date-time-picker';
import FormField from '@/components/form-field';
import {
    OrderPhonesField,
    phonesPayload,
} from '@/components/orders/order-phones-field';
import type { PhoneRow } from '@/components/orders/order-phones-field';
import { OrderStatusDot } from '@/components/orders/order-status-badge';
import { FormAlert } from '@/components/organization/form-alert';
import type { FormDialogControls } from '@/components/organization/form-dialog';
import { FormDialog } from '@/components/organization/form-dialog';
import { Alert, AlertDescription } from '@/components/reui/alert';
import { IconStack } from '@/components/reui/icon-stack';
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
import type { OrderCreateForm, OrderShowroomChoices } from '@/types';

const NONE = 'none';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    options: OrderCreateForm;
};

export function OrderCreateDialog({ open, onOpenChange, options }: Props) {
    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            className="flex max-h-[calc(100vh-3rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-5xl"
        >
            {(controls) => (
                <OrderCreateFields options={options} controls={controls} />
            )}
        </FormDialog>
    );
}

function Sections({ children }: { children: ReactNode[] }) {
    const visible = children.filter(Boolean);

    return (
        <div className="flex flex-col gap-4">
            {visible.map((section, index) => (
                <Fragment key={index}>
                    {index > 0 && <Separator />}
                    {section}
                </Fragment>
            ))}
        </div>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section aria-label={title} className="flex flex-col gap-4">
            {children}
        </section>
    );
}

function OrderCreateFields({
    options,
    controls,
}: {
    options: OrderCreateForm;
    controls: FormDialogControls;
}) {
    const { setBusy, setDirty, requestClose, close } = controls;
    const [failure, setFailure] = useState<string | null>(null);
    const {
        showrooms,
        paymentMethods,
        visitResults,
        defaultPaymentMethodId,
        statuses,
        initialStatusId,
        reasonTitles,
        responsibles,
        selfId,
        maxExtraPhones,
        canComment,
    } = options;
    const single = showrooms.length === 1 ? showrooms[0] : null;
    const [choices, setChoices] = useState<OrderShowroomChoices | null>(
        options.choices,
    );
    const [choicesFailed, setChoicesFailed] = useState(false);
    const requested = useRef<number | null>(null);
    const { submit: request, processing: choicesLoading } = useHttp();
    const sources = choices?.sources ?? null;

    const candidatesFor = (showroomId: number | undefined) =>
        (responsibles ?? []).filter(
            (user) =>
                user.showroom_ids === null ||
                (showroomId !== undefined &&
                    user.showroom_ids.includes(showroomId)),
        );

    const defaultResponsible = (showroomId: number | undefined) =>
        candidatesFor(showroomId).some((user) => user.id === selfId)
            ? String(selfId)
            : '';

    const form = useForm({
        showroom_id: single ? String(single.id) : '',
        source_id: '',
        contact_channel_id: '',
        payment_method_id:
            defaultPaymentMethodId === null
                ? ''
                : String(defaultPaymentMethodId),
        contact_name: '',
        phones: [{ number: '', name: '' }] as PhoneRow[],
        order_status_id:
            initialStatusId === null ? '' : String(initialStatusId),
        responsible_id: defaultResponsible(single?.id),
        reason_id: '',
        reason_comment: '',
        callback_at: '',
        arrival_at: '',
        visit_result_id: '',
        comment: '',
    });

    useEffect(() => setDirty(form.isDirty), [form.isDirty, setDirty]);

    const errors = form.errors as Record<string, string | undefined>;
    const showroomId =
        form.data.showroom_id === ''
            ? undefined
            : Number(form.data.showroom_id);
    const source = sources?.find(
        (item) => String(item.id) === form.data.source_id,
    );
    const candidates = candidatesFor(showroomId);
    const status = statuses.find(
        (item) => String(item.id) === form.data.order_status_id,
    );
    const reasonType = status?.required_reason ?? null;
    const reasons = reasonType && choices ? choices.reasons[reasonType] : [];
    const reason = reasons.find(
        (item) => String(item.id) === form.data.reason_id,
    );

    const chooseShowroom = async (value: string) => {
        const id = Number(value);
        const allowed = candidatesFor(id);

        form.setData((data) => ({
            ...data,
            showroom_id: value,
            source_id: '',
            contact_channel_id: '',
            reason_id: '',
            responsible_id: allowed.some(
                (user) => String(user.id) === data.responsible_id,
            )
                ? data.responsible_id
                : defaultResponsible(id),
        }));
        setChoices(null);
        setChoicesFailed(false);
        requested.current = id;

        try {
            const response = (await request(
                OrderController.createSources({ query: { showroom_id: id } }),
            )) as OrderShowroomChoices;

            if (requested.current === id) {
                setChoices(response);
            }
        } catch {
            if (requested.current === id) {
                setChoicesFailed(true);
            }
        }
    };

    const chooseSource = (value: string) => {
        const next = sources?.find((item) => String(item.id) === value);
        const channel =
            next?.channels.find((item) => item.preferred) ??
            (next?.channels.length === 1 ? next.channels[0] : undefined);

        form.setData((data) => ({
            ...data,
            source_id: value,
            contact_channel_id: channel ? String(channel.id) : '',
        }));
    };

    const chooseStatus = (value: string) => {
        const next = statuses.find((item) => String(item.id) === value);

        form.setData((data) => ({
            ...data,
            order_status_id: value,
            reason_id: '',
            reason_comment: '',
            callback_at: next?.requires_callback_at ? data.callback_at : '',
            arrival_at: next?.requires_arrival_at ? data.arrival_at : '',
            visit_result_id: next?.requires_visit_result
                ? data.visit_result_id
                : '',
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        form.transform((data) => ({
            showroom_id:
                data.showroom_id === '' ? null : Number(data.showroom_id),
            source_id: data.source_id === '' ? null : Number(data.source_id),
            contact_channel_id:
                data.contact_channel_id === ''
                    ? null
                    : Number(data.contact_channel_id),
            payment_method_id:
                data.payment_method_id === ''
                    ? null
                    : Number(data.payment_method_id),
            contact_name: data.contact_name || null,
            phones: phonesPayload(data.phones),
            order_status_id:
                data.order_status_id === ''
                    ? null
                    : Number(data.order_status_id),
            reason_id: data.reason_id === '' ? null : Number(data.reason_id),
            reason_comment: data.reason_comment || null,
            callback_at: data.callback_at || null,
            arrival_at: data.arrival_at || null,
            visit_result_id:
                data.visit_result_id === ''
                    ? null
                    : Number(data.visit_result_id),
            ...(canComment ? { comment: data.comment || null } : {}),
            ...(responsibles
                ? {
                      responsible_id:
                          data.responsible_id === '' ||
                          data.responsible_id === NONE
                              ? null
                              : Number(data.responsible_id),
                  }
                : {}),
        }));

        form.post(
            OrderController.store.url(),
            saveOptions(setFailure, {
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => close(),
            }),
        );
    };

    return (
        <form
            onSubmit={submit}
            className="flex min-h-0 flex-1 flex-col"
            noValidate
        >
            <DialogHeader className="shrink-0 flex-row items-center gap-3 p-4 pe-12">
                <IconStack
                    aria-hidden="true"
                    className="h-12 w-11 shrink-0 text-success"
                >
                    <SparklesIcon className="size-4 text-success" />
                </IconStack>
                <div className="flex flex-col gap-1">
                    <DialogTitle>Новая заявка</DialogTitle>
                    <DialogDescription>
                        Добавьте контакты клиента и источник обращения.
                    </DialogDescription>
                </div>
            </DialogHeader>

            <div className="me-0.5 flex min-h-0 flex-1 flex-col gap-4 overflow-auto px-4 pb-4 [scrollbar-color:rgb(120_120_120/0.4)_transparent] [scrollbar-width:thin]">
                <FormAlert failure={failure} />

                {sources?.length === 0 && (
                    <Alert variant="info">
                        <AlertDescription>
                            В выбранном салоне нет активных источников.
                        </AlertDescription>
                    </Alert>
                )}

                {choicesFailed && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            Не удалось загрузить источники салона. Выберите
                            салон ещё раз.
                        </AlertDescription>
                    </Alert>
                )}

                <Sections>
                    <Section title="Контакты клиента">
                        <OrderPhonesField
                            phones={form.data.phones}
                            maxExtra={maxExtraPhones}
                            errors={errors}
                            onChange={(phones) =>
                                form.setData('phones', phones)
                            }
                            beside={
                                <FormField
                                    label="Имя клиента"
                                    error={errors.contact_name}
                                >
                                    <Input
                                        id="order-contact-name"
                                        value={form.data.contact_name}
                                        onChange={(event) =>
                                            form.setData(
                                                'contact_name',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Необязательно"
                                        maxLength={150}
                                        autoComplete="off"
                                    />
                                </FormField>
                            }
                        />
                    </Section>

                    <Section title="Источник обращения">
                        {(!single || responsibles) && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                {!single && (
                                    <FormField
                                        label={
                                            <>
                                                Автосалон
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </>
                                        }
                                        error={errors.showroom_id}
                                    >
                                        <Select
                                            value={form.data.showroom_id}
                                            onValueChange={chooseShowroom}
                                        >
                                            <SelectTrigger
                                                id="order-showroom"
                                                className="w-full"
                                                aria-required
                                            >
                                                <SelectValue placeholder="Выберите салон" />
                                            </SelectTrigger>
                                            <SelectContent position="popper">
                                                <SelectGroup>
                                                    {showrooms.map((item) => (
                                                        <SelectItem
                                                            key={item.id}
                                                            value={String(
                                                                item.id,
                                                            )}
                                                        >
                                                            {item.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                    </FormField>
                                )}
                                {responsibles && (
                                    <FormField
                                        label={
                                            <>
                                                Ответственный
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </>
                                        }
                                        error={errors.responsible_id}
                                    >
                                        <Select
                                            value={
                                                showroomId === undefined
                                                    ? ''
                                                    : form.data.responsible_id
                                            }
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'responsible_id',
                                                    value,
                                                )
                                            }
                                            disabled={showroomId === undefined}
                                        >
                                            <SelectTrigger
                                                id="order-responsible"
                                                className="w-full"
                                                aria-required
                                            >
                                                <SelectValue
                                                    placeholder={
                                                        showroomId === undefined
                                                            ? 'Сначала выберите салон'
                                                            : 'Выберите ответственного'
                                                    }
                                                />
                                            </SelectTrigger>
                                            <SelectContent position="popper">
                                                <SelectGroup>
                                                    {candidates.map((user) => (
                                                        <SelectItem
                                                            key={user.id}
                                                            value={String(
                                                                user.id,
                                                            )}
                                                        >
                                                            {user.id === selfId
                                                                ? `${user.name} (вы)`
                                                                : user.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                    </FormField>
                                )}
                            </div>
                        )}

                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                label={
                                    <>
                                        Источник
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    </>
                                }
                                error={errors.source_id}
                            >
                                <Select
                                    value={form.data.source_id}
                                    onValueChange={chooseSource}
                                    disabled={!sources || sources.length === 0}
                                >
                                    <SelectTrigger
                                        id="order-source"
                                        className="w-full"
                                        aria-required
                                    >
                                        <SelectValue
                                            placeholder={
                                                choicesLoading
                                                    ? 'Загрузка…'
                                                    : sources
                                                      ? 'Выберите источник'
                                                      : 'Сначала выберите салон'
                                            }
                                        />
                                    </SelectTrigger>
                                    <SelectContent position="popper">
                                        <SelectGroup>
                                            {sources?.map((item) => (
                                                <SelectItem
                                                    key={item.id}
                                                    value={String(item.id)}
                                                >
                                                    {item.name}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                label={
                                    <>
                                        Тип заявки
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    </>
                                }
                                error={errors.contact_channel_id}
                            >
                                <Select
                                    value={form.data.contact_channel_id}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'contact_channel_id',
                                            value,
                                        )
                                    }
                                    disabled={!source}
                                >
                                    <SelectTrigger
                                        id="order-channel"
                                        className="w-full"
                                        aria-required
                                    >
                                        <SelectValue
                                            placeholder={
                                                source
                                                    ? 'Выберите тип заявки'
                                                    : 'Сначала выберите источник'
                                            }
                                        />
                                    </SelectTrigger>
                                    <SelectContent position="popper">
                                        <SelectGroup>
                                            {source?.channels.map((channel) => (
                                                <SelectItem
                                                    key={channel.id}
                                                    value={String(channel.id)}
                                                >
                                                    {channel.name}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                label="Способ оплаты"
                                error={errors.payment_method_id}
                            >
                                <Select
                                    value={form.data.payment_method_id}
                                    onValueChange={(value) =>
                                        form.setData('payment_method_id', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="order-payment-method"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Выберите способ оплаты" />
                                    </SelectTrigger>
                                    <SelectContent position="popper">
                                        <SelectGroup>
                                            {paymentMethods.map((item) => (
                                                <SelectItem
                                                    key={item.id}
                                                    value={String(item.id)}
                                                >
                                                    {item.name}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                label="Статус"
                                error={errors.order_status_id}
                            >
                                <Select
                                    value={form.data.order_status_id}
                                    onValueChange={chooseStatus}
                                >
                                    <SelectTrigger
                                        id="order-status"
                                        className="w-full justify-between"
                                    >
                                        <SelectValue placeholder="Выберите статус" />
                                    </SelectTrigger>
                                    <SelectContent
                                        position="popper"
                                        className="min-w-48"
                                    >
                                        <SelectGroup>
                                            {statuses.map((item) => (
                                                <SelectItem
                                                    key={item.id}
                                                    value={String(item.id)}
                                                >
                                                    <OrderStatusDot
                                                        color={item.color}
                                                    >
                                                        {item.name}
                                                    </OrderStatusDot>
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                            </FormField>
                        </div>

                        {(reasonType ||
                            status?.requires_callback_at ||
                            status?.requires_arrival_at ||
                            status?.requires_visit_result) && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                {reasonType && (
                                    <FormField
                                        label={
                                            <>
                                                {reasonTitles[reasonType]}
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </>
                                        }
                                        error={errors.reason_id}
                                    >
                                        <Select
                                            value={form.data.reason_id}
                                            onValueChange={(value) =>
                                                form.setData('reason_id', value)
                                            }
                                            disabled={!choices}
                                        >
                                            <SelectTrigger
                                                id="order-reason"
                                                className="w-full"
                                                aria-required
                                            >
                                                <SelectValue
                                                    placeholder={
                                                        choices
                                                            ? 'Выберите причину'
                                                            : 'Сначала выберите салон'
                                                    }
                                                />
                                            </SelectTrigger>
                                            <SelectContent position="popper">
                                                <SelectGroup>
                                                    {reasons.map((item) => (
                                                        <SelectItem
                                                            key={item.id}
                                                            value={String(
                                                                item.id,
                                                            )}
                                                        >
                                                            {item.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                    </FormField>
                                )}
                                {reasonType && (
                                    <FormField
                                        label="Пояснение к причине"
                                        error={errors.reason_comment}
                                    >
                                        <Input
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
                                        />
                                    </FormField>
                                )}
                                {status?.requires_callback_at && (
                                    <FormField
                                        label={
                                            <>
                                                Перезвонить
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </>
                                        }
                                        error={errors.callback_at}
                                    >
                                        <DateTimePicker
                                            id="order-callback-at"
                                            value={form.data.callback_at}
                                            onChange={(value) =>
                                                form.setData(
                                                    'callback_at',
                                                    value,
                                                )
                                            }
                                        />
                                    </FormField>
                                )}
                                {status?.requires_arrival_at && (
                                    <FormField
                                        label={
                                            <>
                                                {status.requires_visit_result
                                                    ? 'Дата приезда'
                                                    : 'Приедет'}
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </>
                                        }
                                        error={errors.arrival_at}
                                    >
                                        <DateTimePicker
                                            id="order-arrival-at"
                                            value={form.data.arrival_at}
                                            onChange={(value) =>
                                                form.setData(
                                                    'arrival_at',
                                                    value,
                                                )
                                            }
                                        />
                                    </FormField>
                                )}
                                {status?.requires_visit_result && (
                                    <FormField
                                        label={
                                            <>
                                                Итог визита
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </>
                                        }
                                        error={errors.visit_result_id}
                                    >
                                        <Select
                                            value={form.data.visit_result_id}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'visit_result_id',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id="order-visit-result"
                                                className="w-full"
                                                aria-required
                                            >
                                                <SelectValue placeholder="Выберите итог визита" />
                                            </SelectTrigger>
                                            <SelectContent position="popper">
                                                <SelectGroup>
                                                    {visitResults.map(
                                                        (item) => (
                                                            <SelectItem
                                                                key={item.id}
                                                                value={String(
                                                                    item.id,
                                                                )}
                                                            >
                                                                {item.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                    </FormField>
                                )}
                            </div>
                        )}
                    </Section>

                    {canComment && (
                        <Section title="Комментарий">
                            <FormField
                                label="Комментарий"
                                error={errors.comment}
                            >
                                <Textarea
                                    id="order-comment"
                                    placeholder="Введите комментарий…"
                                    className="w-full"
                                    value={form.data.comment}
                                    onChange={(event) =>
                                        form.setData(
                                            'comment',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={5000}
                                />
                            </FormField>
                        </Section>
                    )}
                </Sections>
            </div>

            <DialogFooter className="shrink-0 border-t p-4">
                <Button
                    type="button"
                    variant="outline"
                    onClick={requestClose}
                    disabled={form.processing}
                >
                    Отмена
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing || !sources?.length}
                >
                    {form.processing && <Spinner />}
                    Создать заявку
                </Button>
            </DialogFooter>
        </form>
    );
}
