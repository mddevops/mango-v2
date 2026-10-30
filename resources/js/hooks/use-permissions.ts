import { usePage } from '@inertiajs/react';
import type { Abilities } from '@/types';

export function usePermissions() {
    const { auth } = usePage().props;
    const permissions = auth.permissions ?? [];

    return {
        can: (permission: string) => permissions.includes(permission),
        allows: (ability: keyof Abilities) =>
            auth.abilities?.[ability] ?? false,
        globalAccess: auth.globalAccess ?? false,
    };
}
