import type { ReactNode } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Frame,
    FrameDescription,
    FrameHeader,
    FramePanel,
    FrameTitle,
} from '@/components/reui/frame';
import { cn } from '@/lib/utils';

export function FormSection({
    title,
    description,
    actions,
    children,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Frame className="[--frame-radius:var(--radius-xl)]">
            <FrameHeader className="flex-row flex-wrap items-center justify-between gap-2">
                <div className="flex flex-col gap-0.5">
                    <FrameTitle>{title}</FrameTitle>
                    {description && (
                        <FrameDescription>{description}</FrameDescription>
                    )}
                </div>
                {actions}
            </FrameHeader>
            <FramePanel>{children}</FramePanel>
        </Frame>
    );
}

export function CheckboxOption({
    id,
    checked,
    onChange,
    disabled,
    label,
    hint,
    aside,
}: {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    disabled?: boolean;
    label: ReactNode;
    hint?: ReactNode;
    aside?: ReactNode;
}) {
    return (
        <label
            htmlFor={id}
            className={cn(
                'flex min-h-11 items-start gap-3 rounded-lg border px-3 py-2.5 transition-colors',
                disabled
                    ? 'cursor-not-allowed opacity-60'
                    : 'cursor-pointer hover:bg-muted/50',
                checked && 'border-primary/40 bg-primary/5',
            )}
        >
            <Checkbox
                id={id}
                checked={checked}
                disabled={disabled}
                onCheckedChange={(value) => onChange(value === true)}
                className="mt-0.5"
            />
            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="flex flex-wrap items-center gap-2 text-sm font-medium">
                    {label}
                    {aside}
                </span>
                {hint && (
                    <span className="text-xs text-muted-foreground">
                        {hint}
                    </span>
                )}
            </span>
        </label>
    );
}
