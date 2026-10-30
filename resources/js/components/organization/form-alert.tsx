import { CircleAlert, RotateCw } from 'lucide-react';
import {
    Alert,
    AlertAction,
    AlertDescription,
    AlertTitle,
} from '@/components/reui/alert';
import { Button } from '@/components/ui/button';

/**
 * Shows errors that are not tied to a visible field: a concurrent edit conflict
 * (lock_version) or a failed save request.
 */
export function FormAlert({
    conflict,
    failure,
}: {
    conflict?: string;
    failure?: string | null;
}) {
    if (conflict) {
        return (
            <Alert variant="warning">
                <CircleAlert />
                <AlertTitle>Запись изменена</AlertTitle>
                <AlertDescription>{conflict}</AlertDescription>
                <AlertAction>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => window.location.reload()}
                    >
                        <RotateCw />
                        Обновить
                    </Button>
                </AlertAction>
            </Alert>
        );
    }

    if (failure) {
        return (
            <Alert variant="destructive">
                <CircleAlert />
                <AlertTitle>Не удалось сохранить</AlertTitle>
                <AlertDescription>{failure}</AlertDescription>
            </Alert>
        );
    }

    return null;
}

export const saveFailureMessage =
    'Сервер не ответил или вернул ошибку. Изменения не сохранены, повторите попытку.';
