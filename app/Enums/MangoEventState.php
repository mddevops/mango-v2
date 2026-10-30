<?php

namespace App\Enums;

/**
 * Processing of a saved Mango event. `accepted` waits for the queue or the recovery
 * command; `failed` stopped after the limited retries and waits for a manual retry.
 */
enum MangoEventState: string
{
    case Accepted = 'accepted';

    case Processed = 'processed';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Ожидает обработки',
            self::Processed => 'Обработано',
            self::Failed => 'Ошибка',
        };
    }
}
