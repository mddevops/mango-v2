import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import FormField from '@/components/form-field';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/security';

type Props = {
    passwordRules: string;
};

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Безопасность" />

            <h1 className="sr-only">Безопасность</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Смена пароля"
                    description="Используйте длинный случайный пароль, чтобы аккаунт оставался защищённым"
                />

                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <FormField
                                label="Текущий пароль"
                                error={errors.current_password}
                            >
                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    autoComplete="current-password"
                                    placeholder="Текущий пароль"
                                />
                            </FormField>

                            <FormField
                                label="Новый пароль"
                                error={errors.password}
                            >
                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    name="password"
                                    autoComplete="new-password"
                                    placeholder="Новый пароль"
                                    passwordrules={props.passwordRules}
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
                                    passwordrules={props.passwordRules}
                                />
                            </FormField>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    Сохранить
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Безопасность',
            href: edit(),
        },
    ],
};
