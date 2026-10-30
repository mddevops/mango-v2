export type PaginationMeta = {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
};

export type Paginated<T> = {
    data: T[];
    meta: PaginationMeta;
};

export type Option = {
    value: string;
    label: string;
};

export type ShowroomRow = {
    id: number;
    code: string;
    name: string;
    address: string | null;
    phone: string | null;
    timezone: string;
    business_day_boundary_enabled: boolean;
    business_day_boundary: string;
    active: boolean;
    lock_version: number;
    can: { update: boolean };
};

export type DepartmentKindOption = Option & {
    requiresShowroom: boolean;
};

export type DepartmentRow = {
    id: number;
    code: string;
    name: string;
    kind: string;
    kind_label: string;
    showroom: { id: number; name: string } | null;
    active: boolean;
    lock_version: number;
    can: { update: boolean };
};

export type UserRow = {
    id: number;
    name: string;
    email: string;
    active: boolean;
    has_global_access: boolean;
    lock_version: number;
    is_self: boolean;
    roles: string[];
    showrooms: string[];
    can: { update: boolean; changeStatus: boolean };
};

export type RoleRow = {
    id: number;
    name: string;
    label: string;
    is_system: boolean;
    permissions_count: number;
    users_count: number;
    can: { update: boolean; delete: boolean };
};

export type PermissionGroup = {
    key: string;
    label: string;
    permissions: { name: string; label: string }[];
};

export type AuditEntry = {
    id: number;
    created_at: string;
    actor: string | null;
    event: string;
    subject_type: string;
    subject: string | null;
    description: string;
    changes: Record<string, unknown> | null;
};
