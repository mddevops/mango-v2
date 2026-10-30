<?php

namespace App\Services;

use App\Models\Showroom;
use App\Models\Source;
use App\Support\BusinessDay;
use App\Support\BusinessDayRule;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The only place that turns an event moment into a business day. The rule always
 * comes from the showroom; sources have no settings of their own. Callers pass the
 * moment explicitly; the calculation never reads the current time. Days are moved with
 * calendar arithmetic in the showroom timezone, never by a fixed number of seconds.
 */
class BusinessDayCalculator
{
    public function ruleFor(Showroom $showroom): BusinessDayRule
    {
        return new BusinessDayRule(
            $showroom->timezone,
            $showroom->business_day_boundary_enabled ? $showroom->business_day_boundary : null,
        );
    }

    public function forShowroom(Showroom $showroom, DateTimeInterface $moment): BusinessDay
    {
        return $this->resolve($this->ruleFor($showroom), $moment);
    }

    public function forSource(Source $source, DateTimeInterface $moment): BusinessDay
    {
        return $this->forShowroom($source->showroom, $moment);
    }

    /**
     * The period of a stored business date under its stored rule. Local midnight of the
     * date always lies inside that period: with a boundary the period ends on the date at
     * the boundary, otherwise it starts at that midnight.
     */
    public function forDate(BusinessDayRule $rule, string $date): BusinessDay
    {
        return $this->resolve($rule, CarbonImmutable::parse($date, $rule->timezone)->startOfDay());
    }

    public function resolve(BusinessDayRule $rule, DateTimeInterface $moment): BusinessDay
    {
        $local = CarbonImmutable::instance($moment)->setTimezone($rule->timezone);

        if ($rule->isCalendar()) {
            $start = $local->startOfDay();

            return new BusinessDay($start->toDateString(), $start, $start->addDay(), $rule);
        }

        [$hour, $minute] = array_map(intval(...), explode(':', (string) $rule->boundary));
        $today = $local->setTime($hour, $minute);
        $end = $local->lt($today) ? $today : $local->startOfDay()->addDay()->setTime($hour, $minute);
        $start = $end->startOfDay()->subDay()->setTime($hour, $minute);

        return new BusinessDay($end->toDateString(), $start, $end, $rule);
    }
}
