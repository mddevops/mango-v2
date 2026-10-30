export type ReferenceOption = {
    id: number;
    name: string;
    active: boolean;
};

export type SourceKindOption = ReferenceOption & {
    classification_ids: number[];
    channel_ids: number[];
};

export type IntegrationLineType = 'phone' | 'sip';

export type IntegrationLine = {
    type: IntegrationLineType;
    value: string;
};

export type MangoConnectionOption = ReferenceOption & {
    showroom_ids: number[];
};

export type SourceRow = {
    id: number;
    code: string;
    name: string;
    url: string | null;
    note: string | null;
    showroom: ReferenceOption;
    kind: ReferenceOption;
    classification: ReferenceOption | null;
    legacy_classification: string | null;
    agency: ReferenceOption | null;
    channels: ReferenceOption[];
    site_intake: {
        active: boolean;
        verification_status: string;
        has_key: boolean | null;
    } | null;
    internal: boolean;
    mango: {
        connection: ReferenceOption;
        lines: IntegrationLine[];
    } | null;
    active: boolean;
    lock_version: number;
    can: { update: boolean };
};

export type SiteRequestState = 'accepted' | 'processed' | 'failed';

export type SiteRequestRow = {
    receipt_id: string;
    received_at: string;
    event_at: string;
    state: SiteRequestState;
    state_label: string;
    order_id: number | null;
    error: string | null;
    attempts: number;
    can_retry: boolean;
};

export type SiteIntakeInfo = {
    source_id: number;
    endpoint: string;
    intake: {
        active: boolean;
        has_key: boolean;
        key_hint: string | null;
        key_issued_at: string | null;
    } | null;
    requests: SiteRequestRow[];
    can: { manageKey: boolean };
};

export type MangoIntakeInfo = {
    connection_id: number;
    webhook_url: string;
    last_event_at: string | null;
    pending_events: number;
    failed_events_count: number;
    review_count: number;
    extensions: {
        id: number;
        extension: string;
        user: { id: number; name: string; active: boolean };
    }[];
    users: { id: number; name: string }[];
    review_calls: {
        id: number;
        started_at: string | null;
        direction: string | null;
        line_number: string | null;
        client: string | null;
        source: string | null;
        showroom: string | null;
        problems: string[];
        order_id: number | null;
    }[];
    failed_events: {
        id: number;
        kind: string;
        received_at: string;
        state: 'accepted' | 'processed' | 'failed';
        state_label: string;
        error: string | null;
        attempts: number;
    }[];
    recordings: {
        counts: Record<'pending' | 'recorded' | 'unavailable', number>;
        problems: {
            id: number;
            received_at: string;
            call_started_at: string | null;
            linked: boolean;
            state: 'unavailable';
            state_label: string;
            error_code: string | null;
            error: string | null;
        }[];
    };
};

export type SourceReferenceTypeValue =
    | 'kinds'
    | 'classifications'
    | 'channels'
    | 'agencies';

/** `App\Enums\IconColor`. */
export type IconColor =
    | 'gray'
    | 'blue'
    | 'sky'
    | 'teal'
    | 'green'
    | 'amber'
    | 'orange'
    | 'red'
    | 'pink'
    | 'violet';

export type SourceReferenceRow = {
    id: number;
    code: string | null;
    name: string;
    /** Lucide icon name; only kinds and channels have one. */
    icon: string | null;
    show_icon: boolean;
    icon_color: IconColor | null;
    active: boolean;
    sources_count: number;
    classification_ids: number[];
    channel_ids: number[];
    classification_names: string[];
    channel_names: string[];
    lock_version: number;
};

export type ConnectionRow = {
    id: number;
    provider: string;
    provider_label: string;
    name: string;
    active: boolean;
    verification_status: string;
    showrooms: ReferenceOption[];
    secrets: Record<string, boolean>;
    sources_count: number;
    lock_version: number;
};
