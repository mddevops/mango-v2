import { Head } from '@inertiajs/react';
import {
    ChevronRight,
    FileIcon,
    Headset,
    Paperclip,
    UserRound,
} from 'lucide-react';
import { PageBody, PageHeader } from '@/components/organization/page-header';
import { Badge } from '@/components/reui/badge';
import { Frame, FrameHeader, FramePanel } from '@/components/reui/frame';
import {
    Timeline,
    TimelineContent,
    TimelineHeader,
    TimelineIndicator,
    TimelineItem,
    TimelineSeparator,
    TimelineTitle,
} from '@/components/reui/timeline';
import { SupportMessageForm } from '@/components/support/support-message-form';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { formatBytes } from '@/hooks/use-file-upload';
import { formatMoment } from '@/lib/order-format';
import { cn } from '@/lib/utils';
import { index as supportIndex, show as showTicket } from '@/routes/support';
import { show as showAttachment } from '@/routes/support/attachments';
import { store as storeMessage } from '@/routes/support/messages';
import type {
    SupportAttachmentItem,
    SupportMessageItem,
    SupportTicketPage,
} from '@/types/support';

function Attachments({ files }: { files: SupportAttachmentItem[] }) {
    if (files.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-2">
            {files.map((file) => {
                const url = showAttachment.url(file.id);

                return (
                    <a
                        key={file.id}
                        href={url}
                        target="_blank"
                        rel="noopener"
                        title={file.name}
                        className="flex max-w-60 items-center gap-2 rounded-lg border bg-background p-1.5 pe-3 text-xs transition-colors hover:bg-muted"
                    >
                        {file.mime_type.startsWith('image/') ? (
                            <img
                                src={url}
                                alt={file.name}
                                loading="lazy"
                                className="size-9 shrink-0 rounded-md border object-cover"
                            />
                        ) : (
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-md border bg-muted">
                                <FileIcon className="size-4 text-muted-foreground" />
                            </span>
                        )}
                        <span className="flex min-w-0 flex-col">
                            <span className="truncate font-medium text-foreground">
                                {file.name}
                            </span>
                            <span className="text-muted-foreground tabular-nums">
                                {formatBytes(file.size, 1)}
                            </span>
                        </span>
                    </a>
                );
            })}
        </div>
    );
}

function roleLabel(message: SupportMessageItem): string {
    if (message.own) {
        return 'Вы';
    }

    return message.from_staff
        ? 'Служба технической поддержки'
        : 'Автор обращения';
}

export default function SupportShow({
    ticket,
    messages,
    upload,
}: SupportTicketPage) {
    const title = ticket.subject;
    return (
        <>
            <Head title={title} />
            <PageBody>
                <PageHeader
                    title={title}
                    description={`Обращение №${ticket.id} · ${ticket.own ? 'вы' : ticket.author} · ${formatMoment(ticket.created_at)}`}
                />

                <Frame>
                    <FramePanel>
                        <SupportMessageForm
                            url={storeMessage.url(ticket.id)}
                            limits={upload}
                            messageLabel={ticket.own ? 'Сообщение' : 'Ответ'}
                        />
                    </FramePanel>
                </Frame>

                <Timeline defaultValue={messages.length} className="pt-2">
                    {messages.map((message, index) => (
                        <TimelineItem
                            key={message.id}
                            step={messages.length - index}
                            className="ms-10 not-last:pb-10"
                        >
                            <TimelineHeader>
                                <TimelineSeparator className="group-data-[orientation=vertical]/timeline:-left-7 group-data-[orientation=vertical]/timeline:h-[calc(100%-1.5rem-0.25rem)] group-data-[orientation=vertical]/timeline:translate-y-7" />
                                <TimelineIndicator
                                    className={cn(
                                        'flex size-6 items-center justify-center border-none group-data-[orientation=vertical]/timeline:-left-7',
                                        message.from_staff
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {message.from_staff ? (
                                        <Headset className="size-3.5" />
                                    ) : (
                                        <UserRound className="size-3.5" />
                                    )}
                                </TimelineIndicator>
                            </TimelineHeader>
                            <TimelineContent>
                                <Frame stacked dense spacing="sm">
                                    <Collapsible
                                        defaultOpen
                                        className="group/collapsible"
                                    >
                                        <CollapsibleTrigger className="flex w-full">
                                            <FrameHeader className="flex grow flex-row items-center justify-between gap-2">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <TimelineTitle className="text-sm font-semibold">
                                                        {roleLabel(message)}
                                                    </TimelineTitle>
                                                    <Badge
                                                        variant={
                                                            message.from_staff
                                                                ? 'success-light'
                                                                : 'info-light'
                                                        }
                                                        size="sm"
                                                    >
                                                        <time
                                                            dateTime={
                                                                message.created_at
                                                            }
                                                            className="tabular-nums"
                                                        >
                                                            {formatMoment(
                                                                message.created_at,
                                                            )}
                                                        </time>
                                                    </Badge>
                                                </div>
                                                <div className="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                                                    {message.attachments
                                                        .length > 0 && (
                                                        <span className="flex items-center gap-1">
                                                            <Paperclip className="size-3" />
                                                            {
                                                                message
                                                                    .attachments
                                                                    .length
                                                            }
                                                        </span>
                                                    )}
                                                    <ChevronRight className="size-4 transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90" />
                                                </div>
                                            </FrameHeader>
                                        </CollapsibleTrigger>
                                        <CollapsibleContent>
                                            <FramePanel className="flex flex-col gap-3">
                                                <p className="text-sm leading-relaxed break-words whitespace-pre-line text-foreground">
                                                    {message.body}
                                                </p>
                                                <Attachments
                                                    files={message.attachments}
                                                />
                                            </FramePanel>
                                        </CollapsibleContent>
                                    </Collapsible>
                                </Frame>
                            </TimelineContent>
                        </TimelineItem>
                    ))}
                </Timeline>
            </PageBody>
        </>
    );
}

SupportShow.layout = ({ ticket }: SupportTicketPage) => ({
    breadcrumbs: [
        { title: 'Поддержка', href: supportIndex() },
        { title: `Обращение №${ticket.id}`, href: showTicket(ticket.id) },
    ],
});
