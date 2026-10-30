import { Link, useHttp } from '@inertiajs/react';
import { Copy, KeyRound, RefreshCw, RotateCcw } from 'lucide-react';
import type { ReactNode } from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import SiteIntakeController from '@/actions/App/Http/Controllers/Sources/SiteIntakeController';
import { Badge } from '@/components/reui/badge';
import { Alert, AlertDescription } from '@/components/reui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { formatMoment } from '@/lib/order-format';
import { show as ordersShow } from '@/routes/orders';
import type { SiteIntakeInfo, SiteRequestState, SourceRow } from '@/types';

const STATE_VARIANTS: Record<
    SiteRequestState,
    'success-light' | 'warning-light' | 'destructive-light'
> = {
    processed: 'success-light',
    accepted: 'warning-light',
    failed: 'destructive-light',
};

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="min-w-0">{children}</dd>
        </div>
    );
}

function CopyButton({ value, label }: { value: string; label: string }) {
    return (
        <Button
            type="button"
            variant="outline"
            size="icon"
            aria-label={label}
            onClick={() => void navigator.clipboard?.writeText(value)}
        >
            <Copy />
        </Button>
    );
}

/**
 * Site API settings of one source for a full administrator. The key comes only in the
 * answer that issues it and lives in this dialog's state until it is closed.
 */
export function SiteIntakeDialog({
    source,
    open,
    onOpenChange,
}: {
    source: SourceRow | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { submit, processing } = useHttp();
    const [info, setInfo] = useState<SiteIntakeInfo | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [issuedKey, setIssuedKey] = useState<string | null>(null);
    const [confirm, setConfirm] = useState<'replace' | 'revoke' | null>(null);
    const submitRef = useRef(submit);
    submitRef.current = submit;

    const sourceId = source?.id ?? null;

    const load = useCallback(async () => {
        if (sourceId === null) {
            return;
        }

        setFailed(null);

        try {
            setInfo(
                (await submitRef.current(
                    SiteIntakeController.show(sourceId),
                )) as SiteIntakeInfo,
            );
        } catch {
            setFailed('Не удалось загрузить настройки API сайта.');
        }
    }, [sourceId]);

    useEffect(() => {
        if (open) {
            setInfo(null);
            setIssuedKey(null);
            void load();
        }
    }, [open, load]);

    const issue = async () => {
        if (!source) {
            return;
        }

        setConfirm(null);
        setFailed(null);

        try {
            const response = (await submit(
                SiteIntakeController.issueKey(source.id),
            )) as { key: string };
            setIssuedKey(response.key);
            await load();
        } catch {
            setFailed(
                'Не удалось выпустить ключ. Проверьте, что приём заявок с сайта включён в настройках источника.',
            );
        }
    };

    const revoke = async () => {
        if (!source) {
            return;
        }

        setConfirm(null);
        setIssuedKey(null);

        try {
            await submit(SiteIntakeController.revokeKey(source.id));
            await load();
        } catch {
            setFailed('Не удалось отозвать ключ.');
        }
    };

    const retry = async (receiptId: string) => {
        try {
            await submit(SiteIntakeController.retry(receiptId));
            await load();
        } catch {
            setFailed('Не удалось повторить обработку.');
        }
    };

    const intake = info?.intake ?? null;

    return (
        <>
            <Dialog open={open && source !== null} onOpenChange={onOpenChange}>
                <DialogContent className="flex max-h-[calc(100vh-3rem)] flex-col gap-4 sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>API сайта · {source?.name}</DialogTitle>
                        <DialogDescription>
                            Сервер сайта отправляет обратные звонки на адрес
                            приёма с ключом этого источника. Ключ не размещается
                            в JavaScript страницы.
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
                                    <Fact label="Адрес приёма">
                                        <div className="flex items-center gap-2">
                                            <Input
                                                readOnly
                                                value={`POST ${info.endpoint}`}
                                                aria-label="Адрес приёма"
                                            />
                                            <CopyButton
                                                value={info.endpoint}
                                                label="Скопировать адрес"
                                            />
                                        </div>
                                    </Fact>
                                    <Fact label="source_id">
                                        <span className="font-mono tabular-nums">
                                            {info.source_id}
                                        </span>
                                    </Fact>
                                    <Fact label="Приём заявок с сайта">
                                        {intake?.active ? (
                                            <Badge variant="success-light">
                                                Включён
                                            </Badge>
                                        ) : (
                                            <Badge variant="warning-light">
                                                {intake
                                                    ? 'Отключён'
                                                    : 'Не настроен'}
                                            </Badge>
                                        )}
                                    </Fact>
                                    <Fact label="Ключ API">
                                        {intake?.has_key ? (
                                            <span className="flex flex-wrap items-center gap-2">
                                                <Badge variant="success-light">
                                                    Действует
                                                </Badge>
                                                <span className="text-sm text-muted-foreground">
                                                    …{intake.key_hint}, выпущен{' '}
                                                    {formatMoment(
                                                        intake.key_issued_at,
                                                    )}
                                                </span>
                                            </span>
                                        ) : (
                                            <Badge variant="warning-light">
                                                Нет ключа
                                            </Badge>
                                        )}
                                    </Fact>
                                </dl>

                                {!intake && (
                                    <p className="text-sm text-muted-foreground">
                                        Включите «Приём заявок с сайта» в
                                        настройках источника, затем выпустите
                                        ключ.
                                    </p>
                                )}

                                {issuedKey && (
                                    <Alert variant="warning">
                                        <AlertDescription className="flex flex-col gap-2">
                                            <span>
                                                Скопируйте ключ сейчас: после
                                                закрытия окна его нельзя будет
                                                посмотреть, только заменить.
                                            </span>
                                            <span className="flex items-center gap-2">
                                                <Input
                                                    readOnly
                                                    value={issuedKey}
                                                    aria-label="Новый ключ API"
                                                    className="font-mono"
                                                    onFocus={(event) =>
                                                        event.target.select()
                                                    }
                                                />
                                                <CopyButton
                                                    value={issuedKey}
                                                    label="Скопировать ключ"
                                                />
                                            </span>
                                        </AlertDescription>
                                    </Alert>
                                )}

                                {info.can.manageKey && intake && (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            disabled={processing}
                                            onClick={() =>
                                                intake.has_key
                                                    ? setConfirm('replace')
                                                    : void issue()
                                            }
                                        >
                                            <KeyRound />
                                            {intake.has_key
                                                ? 'Заменить ключ'
                                                : 'Выпустить ключ'}
                                        </Button>
                                        {intake.has_key && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={processing}
                                                onClick={() =>
                                                    setConfirm('revoke')
                                                }
                                            >
                                                Отозвать ключ
                                            </Button>
                                        )}
                                    </div>
                                )}

                                <Separator />

                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-sm font-medium">
                                        Последние обращения
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        disabled={processing}
                                        onClick={() => void load()}
                                    >
                                        <RefreshCw />
                                        Обновить
                                    </Button>
                                </div>

                                {info.requests.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Обращений через API ещё не было.
                                    </p>
                                ) : (
                                    <ul className="flex flex-col divide-y rounded-md border text-sm">
                                        {info.requests.map((item) => (
                                            <li
                                                key={item.receipt_id}
                                                className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                                            >
                                                <span className="tabular-nums">
                                                    {formatMoment(
                                                        item.received_at,
                                                    )}
                                                </span>
                                                <span className="font-mono text-xs text-muted-foreground">
                                                    {item.receipt_id}
                                                </span>
                                                <Badge
                                                    variant={
                                                        STATE_VARIANTS[
                                                            item.state
                                                        ]
                                                    }
                                                >
                                                    {item.state_label}
                                                </Badge>
                                                {item.order_id !== null && (
                                                    <Link
                                                        href={ordersShow.url(
                                                            item.order_id,
                                                        )}
                                                        className="underline-offset-4 hover:underline"
                                                    >
                                                        Заявка № {item.order_id}
                                                    </Link>
                                                )}
                                                {item.error && (
                                                    <span className="text-destructive">
                                                        {item.error}
                                                    </span>
                                                )}
                                                {item.can_retry && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="ms-auto"
                                                        disabled={processing}
                                                        onClick={() =>
                                                            void retry(
                                                                item.receipt_id,
                                                            )
                                                        }
                                                    >
                                                        <RotateCcw />
                                                        Повторить
                                                    </Button>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </>
                        )}
                    </div>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={confirm !== null}
                onOpenChange={(value) => !value && setConfirm(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {confirm === 'replace'
                                ? 'Заменить ключ API?'
                                : 'Отозвать ключ API?'}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {confirm === 'replace'
                                ? 'Прежний ключ перестанет работать сразу. Новый ключ нужно будет указать на сервере сайта.'
                                : 'Сайт не сможет отправлять обращения, пока не будет выпущен новый ключ.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Отмена</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() =>
                                void (confirm === 'replace' ? issue() : revoke())
                            }
                        >
                            {confirm === 'replace' ? 'Заменить' : 'Отозвать'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
