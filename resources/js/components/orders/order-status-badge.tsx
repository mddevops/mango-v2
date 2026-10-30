import type { BadgeProps } from '@/components/reui/badge';
import { Badge } from '@/components/reui/badge';
import { cn } from '@/lib/utils';
import type { OrderStatusColor } from '@/types';

const variants: Record<OrderStatusColor, BadgeProps['variant']> = {
    gray: 'outline',
    dark: 'invert-light',
    green: 'success-light',
    violet: 'info-light',
    yellow: 'warning-light',
    red: 'destructive-light',
};

const dots: Record<OrderStatusColor, string> = {
    gray: 'bg-zinc-400',
    dark: 'bg-foreground',
    green: 'bg-emerald-400',
    violet: 'bg-violet-500',
    yellow: 'bg-amber-500',
    red: 'bg-red-500',
};

/** The status as a coloured dot and its name, for choosing a status in a select. */
export function OrderStatusDot({
    color,
    children,
}: {
    color: OrderStatusColor;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-center gap-2">
            <div className={cn('size-2 rounded-full', dots[color])} />
            <span>{children}</span>
        </div>
    );
}

export function OrderStatusBadge({
    color,
    children,
    size,
    radius,
    className,
}: {
    color: OrderStatusColor;
    children: React.ReactNode;
    size?: BadgeProps['size'];
    radius?: BadgeProps['radius'];
    className?: string;
}) {
    return (
        <Badge
            variant={variants[color]}
            size={size}
            radius={radius}
            className={className}
        >
            {children}
        </Badge>
    );
}
