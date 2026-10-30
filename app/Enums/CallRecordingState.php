<?php

namespace App\Enums;

/**
 * What Mango reported about a call recording; the audio itself stays at Mango. `pending`
 * — the recording has started but its end was not reported yet; `recorded` — Mango
 * reported a recording kept in its cloud storage (3.1.4 «Completed» with code 1000, or
 * 3.1.8 `record/added`), which still does not guarantee that playback succeeds right
 * now; `unavailable` — Mango said the recording is not kept in its cloud storage
 * (3.1.4: completion code other than 1000, or sent by e-mail only).
 */
enum CallRecordingState: string
{
    case Pending = 'pending';

    case Recorded = 'recorded';

    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Запись не завершена',
            self::Recorded => 'Запись в Mango',
            self::Unavailable => 'Не сохранена в Mango',
        };
    }
}
