import type { ReactNode } from 'react';

export function PageHeader({
    title,
    description,
    actions,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {title}
                </h1>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}

export function PageBody({
    children,
    fluid = false,
}: {
    children: ReactNode;
    /** The full width of the work area, for wide working lists. */
    fluid?: boolean;
}) {
    if (fluid) {
        return (
            <div className="flex flex-1 flex-col gap-4 p-4">{children}</div>
        );
    }

    return (
        <div className="flex flex-1 flex-col p-4">
            <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-5">
                {children}
            </div>
        </div>
    );
}
