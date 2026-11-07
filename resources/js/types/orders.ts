import type { OrderDistribution } from './distribution';
import type { IconColor, ReferenceOption } from './sources';

export type OrderReasonTypeValue = 'trash' | 'drop';

export type OrderStatusColor =
    'gray' | 'dark' | 'green' | 'violet' | 'yellow' | 'red';

export type OrderStatusRow = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    color: OrderStatusColor;
    active: boolean;
    is_initial: boolean;
    /** Label of the system purpose; such a status cannot be disabled or initial. */
    purpose: string | null;
    show_as_tab: boolean;
    requires_callback_at: boolean;
    requires_arrival_at: boolean;
    requires_visit_result: boolean;
    required_reason: OrderReasonTypeValue | null;
    lock_version: number;
};

export type OrderStatusLabel = {
    name: string;
    color: OrderStatusColor;
};

export type OrderRow = {
    id: number;
    received_at: string;
    business_date: string;
    contact_name: string | null;
    phone: string;
    showroom: string;
    source: string;
    channel: string;
    status: OrderStatusLabel;
    responsible: string | null;
    repeat_number: number;
    /** Showroom-local 2026-10-22T10:30. */
    callback_at: string | null;
    arrival_at: string | null;
    updated_at: string | null;
    /** Name of the receiving showroom; says nothing about access to its order. */
    transferred_to: string | null;
    transferred_from: string | null;
};

export type OrderListRow = OrderRow & {
    source_kind: string;
    /** Lucide icon names, only when the reference shows them in the list. */
    source_icon: string | null;
    source_icon_color: IconColor;
    channel_icon: string | null;
    channel_icon_color: IconColor;
    showroom_id: number;
    lock_version: number;
    can: {
        take: boolean;
        assign: boolean;
        distribute: boolean;
        transfer: boolean;
        previous: boolean;
    };
    /** The latest comments, newest first; `comments_count` counts all of them. */
    comments: OrderComment[];
    comments_count: number;
    /** «Срочные», «Просроченные»: seconds to the callback by the server's clock, negative when overdue. */
    callback_in?: number | null;
    /** «Пропущенные»: unhandled missed calls and the latest of them. */
    missed_count?: number;
    last_missed_at?: string | null;
};

export type OrderMissedCall = {
    id: number;
    started_at: string | null;
    source: string | null;
    line: string | null;
};

export type OrderTransferInfo = {
    showroom: string;
    /** Only when the employee may open the linked order. */
    order_id: number | null;
    user: string;
    comment: string | null;
    created_at: string | null;
};

export type RelatedOrder = Omit<
    OrderRow,
    | 'contact_name'
    | 'phone'
    | 'repeat_number'
    | 'callback_at'
    | 'arrival_at'
    | 'updated_at'
    | 'transferred_to'
    | 'transferred_from'
> & {
    can_open: boolean;
};

/** What the site sent with an API callback request; empty values are omitted. */
export type OrderSiteRequest = {
    url: string | null;
    form: string | null;
    utm: Partial<
        Record<
            | 'utm_source'
            | 'utm_medium'
            | 'utm_campaign'
            | 'utm_content'
            | 'utm_term',
            string
        >
    >;
    viewed_vehicles: { section: string; label: string; ids: number[] }[];
};

export type OrderCard = {
    id: number;
    origin: string;
    showroom: { id: number; name: string; timezone: string };
    source: ReferenceOption;
    source_icon: string | null;
    source_icon_color: IconColor;
    channel: ReferenceOption;
    payment_method: ReferenceOption | null;
    status: OrderStatusLabel & { id: number };
    responsible: ReferenceOption | null;
    creator: string | null;
    contact_name: string | null;
    phones: { value: string; formatted: string; name: string | null }[];
    car_interest: string | null;
    callback_at: string | null;
    arrival_at: string | null;
    visit_result: ReferenceOption | null;
    reason: (ReferenceOption & { type: OrderReasonTypeValue | null }) | null;
    reason_comment: string | null;
    event_at: string;
    received_at: string;
    business_date: string;
    business_day_rule: { timezone: string; boundary: string | null };
    repeat_number: number;
    distribution: OrderDistribution | null;
    transfer_out: OrderTransferInfo | null;
    transfer_in: OrderTransferInfo | null;
    site_request: OrderSiteRequest | null;
    created_at: string | null;
    updated_at: string | null;
    lock_version: number;
};

export type OrderStatusOption = ReferenceOption & {
    color: OrderStatusColor;
    requires_callback_at: boolean;
    requires_arrival_at: boolean;
    requires_visit_result: boolean;
    required_reason: OrderReasonTypeValue | null;
};

export type OrderEditOptions = {
    channels: ReferenceOption[];
    paymentMethods: ReferenceOption[];
    visitResults: ReferenceOption[];
    statuses: OrderStatusOption[];
    reasons: Record<
        OrderReasonTypeValue,
        (ReferenceOption & { comment_required: boolean })[]
    >;
    reasonTitles: Record<OrderReasonTypeValue, string>;
    responsibles: ReferenceOption[] | null;
    maxExtraPhones: number;
};

export type OrderComment = {
    id: number;
    author: string;
    body: string;
    created_at: string | null;
};

export type OrderCall = {
    id: number;
    direction: string;
    started_at: string | null;
    ended_at: string | null;
    state: string;
    missed: boolean;
    talk_seconds: number | null;
    answered: string | null;
    source: string | null;
    line: string | null;
    legs: {
        id: number;
        employee: string | null;
        transferred: boolean;
        connected_at: string | null;
        ended_at: string | null;
    }[];
    /** Present only with the `orders.recordings` permission. */
    recordings?: OrderCallRecording[];
};

export type OrderCallRecording = {
    id: number;
    label: string;
    state: 'pending' | 'recorded' | 'unavailable';
    state_label: string;
    audio_url: string | null;
    play_url: string | null;
};

export type OrderHistoryEntry = {
    id: number;
    created_at: string;
    actor: string;
    system: boolean;
    event: string;
    description: string;
    changes: {
        field: string;
        before: string | null;
        after: string | null;
        diff: boolean;
    }[];
};

export type OrderSourceChoice = {
    id: number;
    name: string;
    /** `preferred` marks «Обратный звонок», picked by default. */
    channels: { id: number; name: string; preferred: boolean }[];
};

/** What depends on the showroom chosen in the creation dialog. */
export type OrderShowroomChoices = {
    sources: OrderSourceChoice[];
    reasons: Record<
        OrderReasonTypeValue,
        (ReferenceOption & { comment_required: boolean })[]
    >;
};

export type OrderCreateForm = {
    /** Active showrooms of the scope; the choice is hidden when there is one. */
    showrooms: ReferenceOption[];
    /** Sent at once only with a single showroom, otherwise loaded after the choice. */
    choices: OrderShowroomChoices | null;
    paymentMethods: ReferenceOption[];
    visitResults: ReferenceOption[];
    /** «Не определено» while it is active. */
    defaultPaymentMethodId: number | null;
    statuses: OrderStatusOption[];
    initialStatusId: number | null;
    reasonTitles: Record<OrderReasonTypeValue, string>;
    /** null: no right to choose, the creator becomes responsible. */
    responsibles:
        { id: number; name: string; showroom_ids: number[] | null }[] | null;
    selfId: number;
    maxExtraPhones: number;
    canComment: boolean;
};

export type OrderReasonRow = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    active: boolean;
    comment_required: boolean;
    all_showrooms: boolean;
    showrooms: ReferenceOption[];
    lock_version: number;
};

export type PaymentMethodRow = {
    id: number;
    code: string;
    name: string;
    active: boolean;
    orders_count: number;
    lock_version: number;
};

export type VisitResultRow = PaymentMethodRow;
