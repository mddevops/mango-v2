import { Mail, MailOpen } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { SupportTicketState } from '@/types/support';

const LABELS: Record<SupportTicketState, string> = {
    sent: 'Отправлено, ещё не прочитано',
    read: 'Прочитано',
    unread: 'Новое сообщение',
};

/**
 * Closed yellow envelope: the last message is waiting to be read; open green: it was read;
 * closed green: a new message for the viewer.
 */
export function SupportTicketIcon({
    state,
    className,
}: {
    state: SupportTicketState;
    className?: string;
}) {
    const Icon = state === 'read' ? MailOpen : Mail;

    return (
        <Icon
            role="img"
            aria-label={LABELS[state]}
            className={cn(
                'size-4 shrink-0',
                state === 'sent' ? 'text-warning' : 'text-success',
                className,
            )}
        >
            <title>{LABELS[state]}</title>
        </Icon>
    );
}

export function supportStateLabel(state: SupportTicketState): string {
    return LABELS[state];
}
