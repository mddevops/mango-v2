import type { ComponentProps, CSSProperties } from 'react';
import { cn } from '@/lib/utils';

type ShineBorderProps = ComponentProps<'div'> & {
    /** Runs the scanning light; otherwise the border stays still. */
    active?: boolean;
    /** Gradient classes of the light. */
    gradient?: string;
    /** Border width. */
    borderWidth?: string;
    /** One pass of the light. */
    duration?: string;
};

/**
 * A frame with a light running along the border. The keyframes live once in app.css;
 * with reduced motion the light stands still.
 */
export function ShineBorder({
    active = true,
    gradient = 'from-primary via-primary/40 to-primary',
    borderWidth = '1px',
    duration = '3s',
    className,
    style,
    children,
    ...props
}: ShineBorderProps) {
    return (
        <div
            data-slot="shine-border"
            className={cn(
                'relative overflow-hidden rounded-xl border bg-border p-(--bw)',
                className,
            )}
            style={
                {
                    '--bw': borderWidth,
                    '--duration': duration,
                    ...style,
                } as CSSProperties
            }
            {...props}
        >
            {active && (
                <div
                    aria-hidden
                    className={cn(
                        'animate-shine-scan pointer-events-none absolute inset-0 bg-linear-to-b opacity-90 blur-sm',
                        gradient,
                    )}
                />
            )}
            <div className="relative z-10 h-full rounded-[calc(var(--radius-xl)-var(--bw))] bg-card">
                {children}
            </div>
        </div>
    );
}
