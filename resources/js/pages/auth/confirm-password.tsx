import { Form, Head } from '@inertiajs/react';
import FormField from '@/components/form-field';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    return (
        <>
            <Head title="Подтверждение пароля" />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <FormField label="Пароль" error={errors.password}>
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder="Пароль"
                                autoComplete="current-password"
                                autoFocus
                            />
                        </FormField>

                        <div className="flex items-center">
                            <Button
                                className="w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && <Spinner />}
                                Подтвердить пароль
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'Подтверждение пароля',
    description:
        'Это защищённый раздел приложения. Подтвердите пароль, чтобы продолжить.',
};
