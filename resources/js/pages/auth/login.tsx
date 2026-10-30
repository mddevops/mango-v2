import { Form, Head } from '@inertiajs/react';
import FormField from '@/components/form-field';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';

/** The layout draws an animated grid behind the form, so fields get the same tint without transparency. */
const opaqueField =
    'bg-background dark:bg-background bg-[image:linear-gradient(var(--field-tint),var(--field-tint))] [--field-tint:color-mix(in_oklch,var(--color-input)_30%,transparent)] data-checked:bg-none';

type Props = {
    status?: string;
};

export default function Login({ status }: Props) {
    return (
        <>
            <Head title="Вход" />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <FormField label="Email" error={errors.email}>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="email@example.com"
                                className={opaqueField}
                            />
                        </FormField>

                        <FormField label="Пароль" error={errors.password}>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="Пароль"
                                className={opaqueField}
                            />
                        </FormField>

                        <div className="flex items-center space-x-3">
                            <Checkbox
                                id="remember"
                                name="remember"
                                tabIndex={3}
                                className={opaqueField}
                            />
                            <Label htmlFor="remember">Запомнить меня</Label>
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            tabIndex={4}
                            disabled={processing}
                            data-test="login-button"
                        >
                            {processing && <Spinner />}
                            Войти
                        </Button>
                    </div>
                )}
            </Form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'Вход в аккаунт',
    description: 'Введите email и пароль, чтобы войти',
};
