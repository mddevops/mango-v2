<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * One business-day period, half-open: it contains its start and ends right before its
 * end, which is the start of the next period. The date is a calendar date in the rule's
 * timezone; with a boundary it is the date on which the period ends.
 */
final readonly class BusinessDay
{
    public function __construct(
        public string $date,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public BusinessDayRule $rule,
    ) {}

    /**
     * @return array{date: string, start: string, end: string, rule: array{timezone: string, boundary: string|null}}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'rule' => $this->rule->snapshot(),
        ];
    }
}
