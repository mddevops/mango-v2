<?php

namespace App\Support;

/**
 * The showroom rule in effect when a moment is resolved. A null boundary means the
 * calendar day in the timezone; a 00:00 boundary gives the same periods.
 */
final readonly class BusinessDayRule
{
    public function __construct(
        public string $timezone,
        public ?string $boundary,
    ) {}

    /**
     * @param  array{timezone: string, boundary: string|null}  $snapshot
     */
    public static function fromSnapshot(array $snapshot): self
    {
        return new self($snapshot['timezone'], $snapshot['boundary'] ?? null);
    }

    public function isCalendar(): bool
    {
        return $this->boundary === null || $this->boundary === '00:00';
    }

    /**
     * What gets stored next to a record so later setting changes do not alter it.
     *
     * @return array{timezone: string, boundary: string|null}
     */
    public function snapshot(): array
    {
        return [
            'timezone' => $this->timezone,
            'boundary' => $this->boundary,
        ];
    }
}
