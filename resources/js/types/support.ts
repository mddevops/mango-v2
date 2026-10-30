import type { TableQueryState } from '@/hooks/use-table-query';
import type { Paginated } from '@/types/organization';

export type SupportTicketState = 'sent' | 'read' | 'unread';

export type SupportUploadLimits = {
    max_files: number;
    types: string[];
};

export type SupportTicketRow = {
    id: number;
    subject: string;
    author: string | null;
    last_message_at: string;
    state: SupportTicketState;
};

export type SupportTicketsPage = {
    tickets: Paginated<SupportTicketRow>;
    query: { search: string; sort: 'new' | 'old' };
    hasAny: boolean;
    upload: SupportUploadLimits;
};

export type SupportQueueRow = {
    id: number;
    subject: string;
    author: string;
    created_at: string;
    last_message_at: string;
    state: SupportTicketState;
};

export type SupportQueuePage = {
    tickets: Paginated<SupportQueueRow>;
    filters: TableQueryState;
};

export type SupportAttachmentItem = {
    id: number;
    name: string;
    size: number;
    mime_type: string;
};

export type SupportMessageItem = {
    id: number;
    author: string;
    own: boolean;
    from_staff: boolean;
    body: string;
    created_at: string;
    attachments: SupportAttachmentItem[];
};

export type SupportTicketPage = {
    ticket: {
        id: number;
        subject: string;
        author: string;
        created_at: string;
        own: boolean;
    };
    messages: SupportMessageItem[];
    staff: boolean;
    upload: SupportUploadLimits;
};
