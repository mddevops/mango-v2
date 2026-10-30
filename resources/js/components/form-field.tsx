import type { ReactElement, ReactNode } from 'react';
import { cloneElement } from 'react';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';

type ControlProps = {
    id?: string;
    required?: boolean;
    'aria-invalid'?: boolean;
};

type Props = {
    label: ReactNode;
    children: ReactElement<ControlProps>;
    error?: string;
    description?: ReactNode;
    labelAside?: ReactNode;
    labelClassName?: string;
    className?: string;
};

export default function FormField({
    label,
    children,
    error,
    description,
    labelAside,
    labelClassName,
    className,
}: Props) {
    const { id, required } = children.props;
    const invalid = Boolean(error);

    const fieldLabel = (
        <FieldLabel htmlFor={id} className={labelClassName}>
            {label}
            {required && <span className="text-destructive">*</span>}
        </FieldLabel>
    );

    return (
        <Field data-invalid={invalid || undefined} className={className}>
            {labelAside ? (
                <div className="flex items-center gap-2">
                    {fieldLabel}
                    {labelAside}
                </div>
            ) : (
                fieldLabel
            )}
            {cloneElement(children, {
                'aria-invalid': invalid || undefined,
            })}
            {description && <FieldDescription>{description}</FieldDescription>}
            <FieldError>{error}</FieldError>
        </Field>
    );
}
