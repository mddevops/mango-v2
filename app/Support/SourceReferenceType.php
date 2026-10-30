<?php

namespace App\Support;

use App\Models\Agency;
use App\Models\ContactChannel;
use App\Models\SourceClassification;
use App\Models\SourceKind;

/**
 * Editable reference tables of sources. All of them share the same lifecycle: create,
 * rename, reorder, disable and enable; values are never deleted.
 */
enum SourceReferenceType: string
{
    case Kinds = 'kinds';
    case Classifications = 'classifications';
    case Channels = 'channels';
    case Agencies = 'agencies';

    /**
     * @return class-string<SourceKind|SourceClassification|ContactChannel|Agency>
     */
    public function model(): string
    {
        return match ($this) {
            self::Kinds => SourceKind::class,
            self::Classifications => SourceClassification::class,
            self::Channels => ContactChannel::class,
            self::Agencies => Agency::class,
        };
    }

    public function table(): string
    {
        return match ($this) {
            self::Kinds => 'source_kinds',
            self::Classifications => 'source_classifications',
            self::Channels => 'contact_channels',
            self::Agencies => 'agencies',
        };
    }

    public function hasCode(): bool
    {
        return $this !== self::Agencies;
    }

    /** Kinds and channels may carry a Lucide icon shown in the orders list. */
    public function hasIcon(): bool
    {
        return $this === self::Kinds || $this === self::Channels;
    }

    public function codeFallback(): string
    {
        return match ($this) {
            self::Kinds => 'kind',
            self::Classifications => 'classification',
            self::Channels => 'channel',
            self::Agencies => 'agency',
        };
    }

    public function auditSubject(): string
    {
        return match ($this) {
            self::Kinds => 'source_kind',
            self::Classifications => 'source_classification',
            self::Channels => 'contact_channel',
            self::Agencies => 'agency',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Kinds => 'Виды источников',
            self::Classifications => 'Классификации',
            self::Channels => 'Каналы обращения',
            self::Agencies => 'Агентства',
        };
    }

    public function singular(): string
    {
        return match ($this) {
            self::Kinds => 'Вид источника',
            self::Classifications => 'Классификация',
            self::Channels => 'Канал обращения',
            self::Agencies => 'Агентство',
        };
    }

    public function nameTaken(): string
    {
        return $this->singular().' с таким названием уже существует.';
    }

    /**
     * @return array{created: string, updated: string, activated: string, deactivated: string}
     */
    public function phrases(): array
    {
        return match ($this) {
            self::Kinds => ['created' => 'создан', 'updated' => 'сохранён', 'activated' => 'включён', 'deactivated' => 'отключён'],
            self::Channels => ['created' => 'создан', 'updated' => 'сохранён', 'activated' => 'включён', 'deactivated' => 'отключён'],
            self::Classifications => ['created' => 'создана', 'updated' => 'сохранена', 'activated' => 'включена', 'deactivated' => 'отключена'],
            self::Agencies => ['created' => 'создано', 'updated' => 'сохранено', 'activated' => 'включено', 'deactivated' => 'отключено'],
        };
    }
}
