import { Form, Head } from '@inertiajs/react';
import FormField from '@/components/form-field';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
};

export default function ResetPassword({ token, email, passwordRules }: Props) {
    return (
        <>
            <Head title="Сброс пароля" />

            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <FormField label="Email" error={errors.email}>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                value={email}
                                readOnly
                            />
                        </FormField>

                        <FormField label="Пароль" error={errors.password}>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                autoFocus
                                placeholder="Пароль"
                                passwordrules={passwordRules}
                            />
                        </FormField>

                        <FormField
                            label="Подтверждение пароля"
                            error={errors.password_confirmation}
                        >
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                placeholder="Подтверждение пароля"
                                passwordrules={passwordRules}
                            />
                        </FormField>

                        <Button
                            type="submit"
                            className="mt-4 w-full"
                            disabled={processing}
                            data-test="reset-password-button"
                        >
                            {processing && <Spinner />}
                            Сбросить пароль
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

ResetPassword.layout = {
    title: 'Сброс пароля',
    description: 'Введите новый пароль',
};
