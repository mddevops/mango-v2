import { useForm } from '@inertiajs/react';
import { Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import { FormAlert } from '@/components/organization/form-alert';
import { SupportFileUpload } from '@/components/support/support-file-upload';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { saveOptions } from '@/lib/submit';
import type { SupportUploadLimits } from '@/types/support';

type Props = {
    url: string;
    limits: SupportUploadLimits;
    withSubject?: boolean;
    messageLabel?: string;
    submitLabel?: string;
};

/** A message to support with files; also opens a new request when it has a subject. */
export function SupportMessageForm({
    url,
    limits,
    withSubject = false,
    messageLabel = 'Сообщение',
    submitLabel = 'Отправить',
}: Props) {
    const [failure, setFailure] = useState<string | null>(null);
    const [uploaderKey, setUploaderKey] = useState(0);
    const form = useForm<{ subject: string; body: string; files: File[] }>({
        subject: '',
        body: '',
        files: [],
    });

    const errors = form.errors as Record<string, string | undefined>;
    const fileErrors = Object.entries(errors)
        .filter(([key, message]) => key.startsWith('files') && message)
        .map(([, message]) => message as string);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        form.transform((data) =>
            withSubject
                ? data
                : { body: data.body, files: data.files },
        );

        form.post(
            url,
            saveOptions(setFailure, {
                forceFormData: true,
                onSuccess: () => {
                    form.reset();
                    setUploaderKey((key) => key + 1);
                },
            }),
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <FormAlert failure={failure} />

            {withSubject && (
                <FormField label="Тема" error={form.errors.subject}>
                    <Input
                        id="support-subject"
                        value={form.data.subject}
                        onChange={(event) =>
                            form.setData('subject', event.target.value)
                        }
                        maxLength={200}
                        required
                    />
                </FormField>
            )}

            <FormField label={messageLabel} error={form.errors.body}>
                <Textarea
                    id="support-body"
                    value={form.data.body}
                    onChange={(event) =>
                        form.setData('body', event.target.value)
                    }
                    maxLength={10000}
                    rows={4}
                    required
                />
            </FormField>

            <Field data-invalid={fileErrors.length > 0 || undefined}>
                <FieldLabel>Прикрепить файл</FieldLabel>
                <SupportFileUpload
                    key={uploaderKey}
                    limits={limits}
                    disabled={form.processing}
                    invalid={fileErrors.length > 0}
                    onFilesChange={(files) => form.setData('files', files)}
                />
                <FieldError>{fileErrors[0]}</FieldError>
            </Field>

            <div>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        form.data.body.trim() === '' ||
                        (withSubject && form.data.subject.trim() === '')
                    }
                >
                    {form.processing ? <Spinner /> : <Send />}
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
