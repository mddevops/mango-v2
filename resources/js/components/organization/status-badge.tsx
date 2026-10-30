import { Badge } from '@/components/reui/badge';

export function StatusBadge({
    active,
    activeLabel = 'Активен',
    inactiveLabel = 'Отключён',
}: {
    active: boolean;
    activeLabel?: string;
    inactiveLabel?: string;
}) {
    return (
        <Badge variant={active ? 'success-light' : 'destructive-light'}>
            {active ? activeLabel : inactiveLabel}
        </Badge>
    );
}
