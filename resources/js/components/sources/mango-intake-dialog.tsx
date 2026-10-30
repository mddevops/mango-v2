import { Link, useHttp } from '@inertiajs/react';
import { Copy, Plus, RefreshCw, RotateCcw, Trash2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import MangoIntakeController from '@/actions/App/Http/Controllers/Sources/MangoIntakeController';
import FormField from '@/components/form-field';
import { Alert, AlertDescription } from '@/components/reui/alert';
import { Badge } from '@/components/reui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { formatMoment } from '@/lib/order-format';
import { show as ordersShow } from '@/routes/orders';
import type { ConnectionRow, MangoIntakeInfo } from '@/types';

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="min-w-0">{children}</dd>
        </div>
    );
}

function Section({
    title,
    action,
    children,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-medium">{title}</span>
                {action}
            </div>
            {children}
        </div>
    );
}

/**
 * Call event intake of one Mango connection for a full administrator: the address for
 * the VPBX settings, extensions of employees and what needs attention. Nothing here
 * checks the connection with a real PBX, so it stays «unverified».
 */
export function MangoIntakeDialog({
    connection,
    open,
    onOpenChange,
}: {
    connection: ConnectionRow | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { submit, processing } = useHttp();
    const form = useHttp({ extension: '', user_id: '' });
    const [info, setInfo] = useState<MangoIntakeInfo | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const submitRef = useRef(submit);
    submitRef.current = submit;

    const connectionId = connection?.id ?? null;

    const load = useCallback(async () => {
        if (connectionId === null) {
            return;
        }

        setFailed(null);

        try {
            setInfo(
                (await submitRef.current(
                    MangoIntakeController.show(connectionId),
                )) as MangoIntakeInfo,
            );
        } catch {
            setFailed('Не удалось загрузить настройки приёма событий.');
        }
    }, [connectionId]);

    useEffect(() => {
        if (open) {
            setInfo(null);
            void load();
        }
    }, [open, load]);

    const run = async (action: () => Promise<unknown>, error: string) => {
        setFailed(null);

        try {
            await action();
            await load();
        } catch {
            setFailed(error);
        }
    };

    const addExtension = async (event: FormEvent) => {
        event.preventDefault();

        if (connectionId === null) {
            return;
        }

        try {
            await form.submit(MangoIntakeController.storeExtension(connectionId));
            form.reset();
            await load();
        } catch {
            // Validation errors are shown at the fields.
        }
    };

    const busy = processing || form.processing;

    return (
        <Dialog open={open && connection !== null} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[calc(100vh-3rem)] flex-col gap-4 sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Приём событий · {connection?.name}</DialogTitle>
                    <DialogDescription>
                        Укажите адрес в настройках API виртуальной АТС. Mango
                        подписывает события ключом и солью этого подключения.
                        Подключение остаётся непроверенным, пока его не
                        проверят с реальной АТС.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex min-h-0 flex-col gap-4 overflow-y-auto">
                    {failed && (
                        <Alert variant="destructive">
                            <AlertDescription>{failed}</AlertDescription>
                        </Alert>
                    )}

                    {!info && !failed && (
                        <div className="flex justify-center py-6">
                            <Spinner />
                        </div>
                    )}

                    {info && (
                        <>
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <Fact label="Адрес внешней системы">
                                    <div className="flex items-center gap-2">
                                        <Input
                                            readOnly
                                            value={info.webhook_url}
                                            aria-label="Адрес внешней системы"
                                            onFocus={(event) =>
                                                event.target.select()
                                            }
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            aria-label="Скопировать адрес"
                                            onClick={() =>
                                                void navigator.clipboard?.writeText(
                                                    info.webhook_url,
                                                )
                                            }
                                        >
                                            <Copy />
                                        </Button>
                                    </div>
                                </Fact>
                                <Fact label="Последнее событие">
                                    <span className="text-sm">
                                        {info.last_event_at
                                            ? formatMoment(info.last_event_at)
                                            : 'Событий ещё не было'}
                                    </span>
                                </Fact>
                                <Fact label="Состояние">
                                    <span className="flex flex-wrap gap-1">
                                        <Badge
                                            variant={
                                                info.review_count > 0
                                                    ? 'warning-light'
                                                    : 'success-light'
                                            }
                                        >
                                            На разбор: {info.review_count}
                                        </Badge>
                                        <Badge
                                            variant={
                                                info.failed_events_count > 0
                                                    ? 'destructive-light'
                                                    : 'secondary'
                                            }
                                        >
                                            Ошибок: {info.failed_events_count}
                                        </Badge>
                                        <Badge variant="secondary">
                                            В очереди: {info.pending_events}
                                        </Badge>
                                    </span>
                                </Fact>
                                <Fact label="Проверка с АТС">
                                    <Badge variant="warning-light">
                                        Не проверено
                                    </Badge>
                                </Fact>
                            </dl>

                            <Separator />

                            <Section
                                title="Внутренние номера сотрудников"
                                action={
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        disabled={busy}
                                        onClick={() => void load()}
                                    >
                                        <RefreshCw />
                                        Обновить
                                    </Button>
                                }
                            >
                                <p className="text-sm text-muted-foreground">
                                    Ответственным по звонку становится только
                                    активный сотрудник с доступом к заявкам
                                    салона линии.
                                </p>
                                <form
                                    className="grid items-start gap-2 sm:grid-cols-[10rem_1fr_auto]"
                                    onSubmit={(event) => void addExtension(event)}
                                >
                                    <FormField
                                        label="Внутренний номер"
                                        error={form.errors.extension}
                                    >
                                        <Input
                                            id="mango-extension"
                                            value={form.data.extension}
                                            maxLength={32}
                                            onChange={(event) =>
                                                form.setData(
                                                    'extension',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </FormField>
                                    <FormField
                                        label="Сотрудник"
                                        error={form.errors.user_id}
                                    >
                                        <Select
                                            value={form.data.user_id}
                                            onValueChange={(value) =>
                                                form.setData('user_id', value)
                                            }
                                        >
                                            <SelectTrigger
                                                id="mango-extension-user"
                                                className="w-full"
                                            >
                                                <SelectValue placeholder="Выберите сотрудника" />
                                            </SelectTrigger>
                                            <SelectContent position="popper">
                                                <SelectGroup>
                                                    {info.users.map((user) => (
                                                        <SelectItem
                                                            key={user.id}
                                                            value={String(
                                                                user.id,
                                                            )}
                                                        >
                                                            {user.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                    </FormField>
                                    <Button
                                        type="submit"
                                        className="sm:mt-6"
                                        disabled={busy}
                                    >
                                        <Plus />
                                        Добавить
                                    </Button>
                                </form>

                                {info.extensions.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Внутренние номера не заданы.
                                    </p>
                                ) : (
                                    <ul className="flex flex-col divide-y rounded-md border text-sm">
                                        {info.extensions.map((item) => (
                                            <li
                                                key={item.id}
                                                className="flex items-center gap-3 px-3 py-2"
                                            >
                                                <span className="w-24 font-mono tabular-nums">
                                                    {item.extension}
                                                </span>
                                                <span>{item.user.name}</span>
                                                {!item.user.active && (
                                                    <Badge variant="secondary">
                                                        Неактивен
                                                    </Badge>
                                                )}
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="ms-auto"
                                                    aria-label={`Удалить внутренний номер ${item.extension}`}
                                                    disabled={busy}
                                                    onClick={() =>
                                                        void run(
                                                            () =>
                                                                submit(
                                                                    MangoIntakeController.destroyExtension(
                                                                        [
                                                                            info.connection_id,
                                                                            item.id,
                                                                        ],
                                                                    ),
                                                                ),
                                                            'Не удалось удалить внутренний номер.',
                                                        )
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Section>

                            <Separator />

                            <Section title="Звонки на разбор">
                                {info.review_calls.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Звонков, требующих разбора, нет.
                                    </p>
                                ) : (
                                    <ul className="flex flex-col divide-y rounded-md border text-sm">
                                        {info.review_calls.map((call) => (
                                            <li
                                                key={call.id}
                                                className="flex flex-col gap-1 px-3 py-2"
                                            >
                                                <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                                    <span className="tabular-nums">
                                                        {call.started_at
                                                            ? formatMoment(
                                                                  call.started_at,
                                                              )
                                                            : '—'}
                                                    </span>
                                                    {call.direction && (
                                                        <span>
                                                            {call.direction}
                                                        </span>
                                                    )}
                                                    <span className="text-muted-foreground">
                                                        Линия:{' '}
                                                        {call.line_number ??
                                                            'не передана'}
                                                    </span>
                                                    {call.client && (
                                                        <span className="tabular-nums">
                                                            {call.client}
                                                        </span>
                                                    )}
                                                    {call.source && (
                                                        <span className="text-muted-foreground">
                                                            {call.source}
                                                        </span>
                                                    )}
                                                    {call.order_id !==
                                                        null && (
                                                        <Link
                                                            href={ordersShow.url(
                                                                call.order_id,
                                                            )}
                                                            className="underline-offset-4 hover:underline"
                                                        >
                                                            Заявка №{' '}
                                                            {call.order_id}
                                                        </Link>
                                                    )}
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="ms-auto"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            void run(
                                                                () =>
                                                                    submit(
                                                                        MangoIntakeController.rematch(
                                                                            [
                                                                                info.connection_id,
                                                                                call.id,
                                                                            ],
                                                                        ),
                                                                    ),
                                                                'Не удалось повторить сопоставление.',
                                                            )
                                                        }
                                                    >
                                                        <RotateCcw />
                                                        Повторить сопоставление
                                                    </Button>
                                                </span>
                                                {call.problems.map(
                                                    (problem) => (
                                                        <span
                                                            key={problem}
                                                            className="text-warning-foreground"
                                                        >
                                                            {problem}
                                                        </span>
                                                    ),
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Section>

                            {info.failed_events.length > 0 && (
                                <>
                                    <Separator />
                                    <Section title="События с ошибками">
                                        <ul className="flex flex-col divide-y rounded-md border text-sm">
                                            {info.failed_events.map((item) => (
                                                <li
                                                    key={item.id}
                                                    className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                                                >
                                                    <span className="tabular-nums">
                                                        {formatMoment(
                                                            item.received_at,
                                                        )}
                                                    </span>
                                                    <span className="font-mono text-xs text-muted-foreground">
                                                        {item.kind}
                                                    </span>
                                                    <Badge
                                                        variant={
                                                            item.state ===
                                                            'failed'
                                                                ? 'destructive-light'
                                                                : 'warning-light'
                                                        }
                                                    >
                                                        {item.state_label}
                                                    </Badge>
                                                    {item.error && (
                                                        <span className="text-destructive">
                                                            {item.error}
                                                        </span>
                                                    )}
                                                    <span className="text-muted-foreground">
                                                        Попыток:{' '}
                                                        {item.attempts}
                                                    </span>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="ms-auto"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            void run(
                                                                () =>
                                                                    submit(
                                                                        MangoIntakeController.retry(
                                                                            [
                                                                                info.connection_id,
                                                                                item.id,
                                                                            ],
                                                                        ),
                                                                    ),
                                                                'Не удалось повторить обработку.',
                                                            )
                                                        }
                                                    >
                                                        <RotateCcw />
                                                        Повторить
                                                    </Button>
                                                </li>
                                            ))}
                                        </ul>
                                    </Section>
                                </>
                            )}

                            <Separator />

                            <Section title="Записи разговоров">
                                <span className="flex flex-wrap gap-1">
                                    <Badge variant="info-light">
                                        Запись в Mango:{' '}
                                        {info.recordings.counts.recorded}
                                    </Badge>
                                    <Badge variant="secondary">
                                        Не завершена:{' '}
                                        {info.recordings.counts.pending}
                                    </Badge>
                                    <Badge variant="secondary">
                                        Не сохранено в Mango:{' '}
                                        {info.recordings.counts.unavailable}
                                    </Badge>
                                </span>
                                <p className="text-sm text-muted-foreground">
                                    Аудио хранится в Mango, CRM хранит только
                                    сведения о записях. Прослушивание — в
                                    карточке заявки при праве «Прослушивание
                                    записей разговоров»; в личном кабинете
                                    Mango должна быть включена выдача ссылок на
                                    записи (API, раздел 3.5.2).
                                </p>
                                {info.recordings.problems.length > 0 && (
                                    <ul className="flex flex-col divide-y rounded-md border text-sm">
                                        {info.recordings.problems.map(
                                            (item) => (
                                                <li
                                                    key={item.id}
                                                    className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                                                >
                                                    <span className="tabular-nums">
                                                        {formatMoment(
                                                            item.call_started_at ??
                                                                item.received_at,
                                                        )}
                                                    </span>
                                                    <Badge variant="warning-light">
                                                        {item.state_label}
                                                    </Badge>
                                                    {item.error && (
                                                        <span className="text-muted-foreground">
                                                            {item.error}
                                                        </span>
                                                    )}
                                                    {!item.linked && (
                                                        <span className="text-muted-foreground">
                                                            Звонок ещё не
                                                            получен
                                                        </span>
                                                    )}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                )}
                            </Section>
                        </>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
