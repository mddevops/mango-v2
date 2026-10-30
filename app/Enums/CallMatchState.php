<?php

namespace App\Enums;

/**
 * How a call was matched to a source, a showroom and an order. The review states keep
 * the call without an order until the settings are fixed and the matching is repeated.
 */
enum CallMatchState: string
{
    case Pending = 'pending';

    case Linked = 'linked';

    case NoOrder = 'no_order';

    case Technical = 'technical';

    case Internal = 'internal';

    case UnknownLine = 'unknown_line';

    case AmbiguousLine = 'ambiguous_line';

    case SourceUnavailable = 'source_unavailable';

    case NoClientPhone = 'no_client_phone';

    public function needsReview(): bool
    {
        return in_array($this, [self::UnknownLine, self::AmbiguousLine, self::SourceUnavailable, self::NoClientPhone], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Направление ещё не определено',
            self::Linked => 'Связан с заявкой',
            self::NoOrder => 'Заявка салона по номеру не найдена',
            self::Technical => 'Технический звонок Mango (команда или обратный вызов)',
            self::Internal => 'Внутренний звонок',
            self::UnknownLine => 'Линия не настроена в подключении',
            self::AmbiguousLine => 'Линия подходит нескольким источникам',
            self::SourceUnavailable => 'Источник, салон или канал «Прямой звонок» недоступны',
            self::NoClientPhone => 'Номер клиента скрыт или не российский',
        };
    }
}
