<?php

namespace Tests\Feature\BusinessDay;

use App\Models\Showroom;
use App\Services\BusinessDayCalculator;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BusinessDayCalculatorTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function boundaryMoments(): array
    {
        return [
            'перед границей' => ['2026-10-21 18:59:59', '2026-10-21', '2026-10-20T19:00:00+03:00', '2026-10-21T19:00:00+03:00'],
            'ровно на границе' => ['2026-10-21 19:00:00', '2026-10-22', '2026-10-21T19:00:00+03:00', '2026-10-22T19:00:00+03:00'],
            'после границы' => ['2026-10-21 19:00:01', '2026-10-22', '2026-10-21T19:00:00+03:00', '2026-10-22T19:00:00+03:00'],
            'конец следующего периода' => ['2026-10-22 18:59:59', '2026-10-22', '2026-10-21T19:00:00+03:00', '2026-10-22T19:00:00+03:00'],
            'полночь внутри периода' => ['2026-10-22 00:00:00', '2026-10-22', '2026-10-21T19:00:00+03:00', '2026-10-22T19:00:00+03:00'],
            'переход месяца' => ['2026-10-31 19:00:00', '2026-11-01', '2026-10-31T19:00:00+03:00', '2026-11-01T19:00:00+03:00'],
            'переход года после границы' => ['2026-12-31 19:30:00', '2027-01-01', '2026-12-31T19:00:00+03:00', '2027-01-01T19:00:00+03:00'],
            'переход года до границы' => ['2026-12-31 18:00:00', '2026-12-31', '2026-12-30T19:00:00+03:00', '2026-12-31T19:00:00+03:00'],
            'первое января' => ['2027-01-01 10:00:00', '2027-01-01', '2026-12-31T19:00:00+03:00', '2027-01-01T19:00:00+03:00'],
        ];
    }

    #[DataProvider('boundaryMoments')]
    public function test_boundary_assigns_date_of_period_end(string $moment, string $date, string $start, string $end): void
    {
        $day = $this->resolve($this->showroom(), $moment);

        $this->assertSame($date, $day->date);
        $this->assertSame($start, $day->start->toIso8601String());
        $this->assertSame($end, $day->end->toIso8601String());
    }

    public function test_consecutive_periods_have_no_gaps_or_overlaps(): void
    {
        $showroom = $this->showroom();
        $calculator = app(BusinessDayCalculator::class);
        $day = $this->resolve($showroom, '2026-12-29 12:00:00');

        for ($i = 0; $i < 5; $i++) {
            $next = $calculator->forShowroom($showroom, $day->end);

            $this->assertTrue($next->start->equalTo($day->end));
            $this->assertNotSame($day->date, $next->date);
            $this->assertSame($day->date, $calculator->forShowroom($showroom, $day->end->subSecond())->date);

            $day = $next;
        }
    }

    public function test_disabled_boundary_uses_local_calendar_day(): void
    {
        $showroom = $this->showroom(enabled: false);
        $day = $this->resolve($showroom, '2026-10-21 23:59:59');

        $this->assertSame('2026-10-21', $day->date);
        $this->assertSame('2026-10-21T00:00:00+03:00', $day->start->toIso8601String());
        $this->assertSame('2026-10-22T00:00:00+03:00', $day->end->toIso8601String());
        $this->assertSame(['timezone' => 'Europe/Moscow', 'boundary' => null], $day->rule->snapshot());

        $this->assertSame('2026-10-22', $this->resolve($showroom, '2026-10-22 00:00:00')->date);
        $this->assertSame('2027-01-01', $this->resolve($showroom, '2027-01-01 00:00:00')->date);
    }

    public function test_midnight_boundary_equals_calendar_day(): void
    {
        foreach (['2026-10-21 00:00:00', '2026-10-21 12:00:00', '2026-10-21 23:59:59', '2026-12-31 23:59:59', '2027-01-01 00:00:00'] as $moment) {
            $midnight = $this->resolve($this->showroom(boundary: '00:00'), $moment);
            $calendar = $this->resolve($this->showroom(enabled: false), $moment);

            $this->assertSame($calendar->date, $midnight->date, $moment);
            $this->assertTrue($calendar->start->equalTo($midnight->start), $moment);
            $this->assertTrue($calendar->end->equalTo($midnight->end), $moment);
        }
    }

    public function test_moment_is_read_in_showroom_timezone(): void
    {
        $utc = CarbonImmutable::parse('2026-10-21 15:30:00', 'UTC');
        $calculator = app(BusinessDayCalculator::class);

        $this->assertSame('2026-10-21', $calculator->forShowroom($this->showroom(), $utc)->date);

        $yekaterinburg = $calculator->forShowroom($this->showroom(timezone: 'Asia/Yekaterinburg'), $utc);
        $this->assertSame('2026-10-22', $yekaterinburg->date);
        $this->assertSame('2026-10-21T19:00:00+05:00', $yekaterinburg->start->toIso8601String());
        $this->assertSame('2026-10-21T14:00:00+00:00', $yekaterinburg->start->utc()->toIso8601String());

        $kamchatka = $calculator->forShowroom($this->showroom(timezone: 'Asia/Kamchatka', enabled: false), CarbonImmutable::parse('2026-10-21 12:30:00', 'UTC'));
        $this->assertSame('2026-10-22', $kamchatka->date);
    }

    public function test_result_does_not_depend_on_current_time(): void
    {
        $moment = CarbonImmutable::parse('2026-10-21 18:00:00', 'Europe/Moscow');

        CarbonImmutable::setTestNow('2030-01-01 23:00:00');

        try {
            $day = app(BusinessDayCalculator::class)->forShowroom($this->showroom(), $moment);
        } finally {
            CarbonImmutable::setTestNow();
        }

        $this->assertSame('2026-10-21', $day->date);
    }

    public function test_result_contains_only_period_and_rule_snapshot(): void
    {
        $this->assertSame([
            'date' => '2026-10-21',
            'start' => '2026-10-20T19:00:00+03:00',
            'end' => '2026-10-21T19:00:00+03:00',
            'rule' => ['timezone' => 'Europe/Moscow', 'boundary' => '19:00'],
        ], $this->resolve($this->showroom(), '2026-10-21 18:00:00')->toArray());
    }

    private function showroom(string $timezone = 'Europe/Moscow', bool $enabled = true, string $boundary = '19:00'): Showroom
    {
        return new Showroom([
            'name' => 'Салон',
            'timezone' => $timezone,
            'business_day_boundary_enabled' => $enabled,
            'business_day_boundary' => $boundary,
        ]);
    }

    private function resolve(Showroom $showroom, string $localMoment): BusinessDay
    {
        return app(BusinessDayCalculator::class)->forShowroom($showroom, CarbonImmutable::parse($localMoment, $showroom->timezone));
    }
}
