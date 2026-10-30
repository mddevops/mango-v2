import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { PageBody, PageHeader } from '@/components/organization/page-header';
import {
    Frame,
    FrameFooter,
    FrameHeader,
    FramePanel,
    FrameTitle,
} from '@/components/reui/frame';
import { SupportDeleteTicket } from '@/components/support/support-delete-ticket';
import { SupportHint } from '@/components/support/support-hint';
import { SupportMessageForm } from '@/components/support/support-message-form';
import { SupportTicketIcon } from '@/components/support/support-ticket-icon';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMoment } from '@/lib/order-format';
import {
    index as supportIndex,
    show as showTicket,
    store as storeTicket,
} from '@/routes/support';
import type { SupportTicketRow, SupportTicketsPage } from '@/types/support';

const LIST_PROPS = ['tickets', 'query', 'hasAny'];

function ChatIllustration() {
    return (
        <svg
            width="180"
            height="120"
            viewBox="0 0 180 120"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >
            <rect
                x="20"
                y="20"
                width="80"
                height="44"
                rx="12"
                className="fill-muted stroke-border dark:fill-muted/60"
                strokeWidth="1.5"
            />
            <path
                d="M36 64 L32 76 L48 64"
                className="fill-muted stroke-border dark:fill-muted/60"
                strokeWidth="1.5"
                strokeLinejoin="round"
            />
            <rect x="32" y="32" width="48" height="4" rx="2" className="fill-muted-foreground/20" />
            <rect x="32" y="42" width="36" height="4" rx="2" className="fill-muted-foreground/15" />
            <circle cx="32" cy="52" r="3" className="fill-muted-foreground/12" />
            <rect
                x="80"
                y="50"
                width="80"
                height="40"
                rx="12"
                className="fill-primary/10 stroke-primary/30 dark:fill-primary/15"
                strokeWidth="1.5"
            />
            <path
                d="M144 90 L148 100 L132 90"
                className="fill-primary/10 stroke-primary/30 dark:fill-primary/15"
                strokeWidth="1.5"
                strokeLinejoin="round"
            />
            <rect x="92" y="62" width="52" height="4" rx="2" className="fill-primary/20" />
            <rect x="92" y="72" width="32" height="4" rx="2" className="fill-primary/15" />
            <circle cx="14" cy="46" r="2" className="fill-muted-foreground/10" />
            <circle cx="168" cy="66" r="2" className="fill-primary/15" />
            <circle cx="110" cy="16" r="2.5" className="fill-muted-foreground/10" />
        </svg>
    );
}

function TicketPanel({ ticket }: { ticket: SupportTicketRow }) {
    return (
        <FramePanel className="flex items-start gap-3">
            <SupportTicketIcon state={ticket.state} className="mt-0.5" />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <Link
                    href={showTicket(ticket.id)}
                    className="truncate text-sm font-medium text-primary hover:underline"
                >
                    {ticket.subject}
                </Link>
                <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    <span>
                        <span className="font-medium">ID:</span>{' '}
                        <span className="tabular-nums">{ticket.id}</span>
                    </span>
                    <span>
                        <span className="font-medium">Последнее сообщение:</span>{' '}
                        <span className="tabular-nums">
                            {formatMoment(ticket.last_message_at)}
                        </span>
                    </span>
                    {ticket.author && (
                        <span>
                            <span className="font-medium">Автор:</span>{' '}
                            {ticket.author}
                        </span>
                    )}
                </div>
            </div>
            <SupportDeleteTicket ticket={ticket} only={LIST_PROPS} />
        </FramePanel>
    );
}

export default function SupportIndex({
    tickets,
    query,
    hasAny,
    upload,
}: SupportTicketsPage) {
    const [search, setSearch] = useState(query.search);
    const firstRun = useRef(true);

    const visit = (params: { search?: string; sort?: string; page?: number }) => {
        const next = { search, sort: query.sort, ...params };

        router.get(
            supportIndex.url({
                query: {
                    search: next.search || undefined,
                    sort: next.sort === 'old' ? 'old' : undefined,
                    page: next.page && next.page > 1 ? next.page : undefined,
                },
            }),
            {},
            {
                only: LIST_PROPS,
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    useEffect(() => {
        if (firstRun.current) {
            firstRun.current = false;

            return;
        }

        const timer = window.setTimeout(() => visit({ search }), 350);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const { current_page: page, last_page: lastPage } = tickets.meta;

    return (
        <>
            <Head title="Помощь и поддержка" />
            <PageBody>
                <PageHeader title="Помощь и поддержка" />

                <SupportHint />

                <Frame>
                    <FramePanel>
                        <SupportMessageForm
                            url={storeTicket.url()}
                            limits={upload}
                            withSubject
                        />
                    </FramePanel>
                </Frame>

                <Frame>
                    <FrameHeader className="flex-row flex-wrap items-center justify-between gap-2">
                        <FrameTitle>Созданные тикеты</FrameTitle>
                        <div className="flex flex-wrap items-center gap-2">
                            <Select
                                value={query.sort}
                                onValueChange={(sort) => visit({ sort })}
                            >
                                <SelectTrigger
                                    size="sm"
                                    className="w-32"
                                    aria-label="Порядок"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="new">Новые</SelectItem>
                                    <SelectItem value="old">Старые</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputGroup className="h-8 w-56">
                                <InputGroupAddon>
                                    <Search />
                                </InputGroupAddon>
                                <InputGroupInput
                                    type="search"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Тема или ID"
                                    aria-label="Поиск обращений"
                                />
                            </InputGroup>
                        </div>
                    </FrameHeader>

                    {!hasAny ? (
                        <FramePanel>
                            <Empty className="py-12">
                                <EmptyHeader>
                                    <EmptyMedia>
                                        <ChatIllustration />
                                    </EmptyMedia>
                                    <EmptyTitle>Обращений пока нет</EmptyTitle>
                                    <EmptyDescription>
                                        Опишите вопрос в форме выше — обращение
                                        и ответы поддержки появятся здесь.
                                    </EmptyDescription>
                                </EmptyHeader>
                                <EmptyContent>
                                    <Button
                                        type="button"
                                        onClick={() =>
                                            document
                                                .getElementById('support-subject')
                                                ?.focus()
                                        }
                                    >
                                        Написать обращение
                                    </Button>
                                </EmptyContent>
                            </Empty>
                        </FramePanel>
                    ) : tickets.data.length === 0 ? (
                        <FramePanel className="py-10 text-center text-sm text-muted-foreground">
                            Ничего не найдено
                        </FramePanel>
                    ) : (
                        tickets.data.map((ticket) => (
                            <TicketPanel key={ticket.id} ticket={ticket} />
                        ))
                    )}

                    {lastPage > 1 && (
                        <FrameFooter className="flex-row items-center justify-end gap-2">
                            <span className="text-xs text-muted-foreground tabular-nums">
                                Страница {page} из {lastPage}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon-sm"
                                aria-label="Предыдущая страница"
                                disabled={page <= 1}
                                onClick={() => visit({ page: page - 1 })}
                            >
                                <ChevronLeft />
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon-sm"
                                aria-label="Следующая страница"
                                disabled={page >= lastPage}
                                onClick={() => visit({ page: page + 1 })}
                            >
                                <ChevronRight />
                            </Button>
                        </FrameFooter>
                    )}
                </Frame>
            </PageBody>
        </>
    );
}

SupportIndex.layout = {
    breadcrumbs: [{ title: 'Поддержка', href: supportIndex() }],
};
