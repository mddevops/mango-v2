<?php

namespace App\Enums;

/**
 * Processing of an accepted site request. `accepted` waits for the queue (or for the
 * recovery command); `failed` stopped after the limited retries and waits for a manual
 * retry. The site's receipt is the same in every state.
 */
enum SiteRequestState: string
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
