import { Badge } from '@/components/reui/badge';

/** «Повтор N»: the N-th earlier order of this client in the same showroom. */
export function OrderRepeatBadge({ count }: { count: number }) {
    return count > 0 ? (
        <Badge variant="warning-light">Повтор {count}</Badge>
    ) : null;
}
