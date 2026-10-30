import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import { FormAlert } from '@/components/organization/form-alert';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { saveOptions } from '@/lib/submit';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    url: string;
    userName: string;
    passwordRules: string;
};

export function SetPasswordDialog({ open, onOpenChange, ...props }: Props) {
    const [busy, setBusy] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !busy && onOpenChange(next)}
        >
            <DialogContent className="sm:max-w-md">
                <PasswordForm
                    {...props}
                    onBusy={setBusy}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

function PasswordForm({
    url,
    userName,
    passwordRules,
    onBusy,
    onDone,
}: Omit<Props, 'open' | 'onOpenChange'> & {
    onBusy: (busy: boolean) => void;
    onDone: () => void;
}) {
    const [failure, setFailure] = useState<string | null>(null);
    const form = useForm({ password: '', password_confirmation: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        form.put(
            url,
            saveOptions(setFailure, {
                preserveState: true,
                onStart: () => onBusy(true),
                onFinish: () => {
                    onBusy(false);
                    form.reset();
                },
                onSuccess: () => onDone(),
            }),
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <DialogHeader>
                <DialogTitle>Новый пароль</DialogTitle>
                <DialogDescription>
                    Пароль для «{userName}». Все открытые сессии сотрудника
                    будут завершены.
                </DialogDescription>
            </DialogHeader>

            <FormAlert failure={failure} />

            <div className="flex flex-col gap-4">
                <FormField label="Пароль" error={form.errors.password}>
                    <PasswordInput
                        id="new-password"
                        value={form.data.password}
                        onChange={(event) =>
                            form.setData('password', event.target.value)
                        }
                        required
                        autoComplete="new-password"
                        passwordrules={passwordRules}
                        autoFocus
                    />
                </FormField>
                <FormField
                    label="Повтор пароля"
                    error={form.errors.password_confirmation}
                >
                    <PasswordInput
                        id="new-password-confirmation"
                        value={form.data.password_confirmation}
                        onChange={(event) =>
                            form.setData(
                                'password_confirmation',
                                event.target.value,
                            )
                        }
                        required
                        autoComplete="new-password"
                    />
                </FormField>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onDone}
                    disabled={form.processing}
                >
                    Отмена
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    Установить пароль
                </Button>
            </DialogFooter>
        </form>
    );
}
