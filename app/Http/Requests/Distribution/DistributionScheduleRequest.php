<?php

namespace App\Http\Requests\Distribution;

use App\Http\Requests\Organization\Concerns\ReadsValidatedValues;
use App\Services\DistributionScheduleManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Access to the showroom of the schedule is checked by DistributionScheduleManager.
 *
 * @phpstan-import-type ScheduleData from DistributionScheduleManager
 */
class DistributionScheduleRequest extends FormRequest
{
    use ReadsValidatedValues;

    public const MAX_EXCEPTIONS = 366;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('distribution-schedules.manage');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'participates' => ['required', 'boolean'],
            'weekdays' => ['required', 'array', 'size:7'],
            'weekdays.*' => ['boolean'],
            'exceptions' => ['present', 'array', 'max:'.self::MAX_EXCEPTIONS],
            'exceptions.*' => ['array'],
            'exceptions.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'exceptions.*.works' => ['required', 'boolean'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return ScheduleData
     */
    public function scheduleData(): array
    {
        /** @var list<mixed> $weekdays */
        $weekdays = $this->validated('weekdays');
        /** @var list<array{date: string, works: mixed}> $exceptions */
        $exceptions = $this->validated('exceptions');

        return [
            'participates' => $this->boolean('participates'),
            'weekdays' => array_values(array_map(fn (mixed $day): bool => filter_var($day, FILTER_VALIDATE_BOOL), $weekdays)),
            'exceptions' => array_values(array_map(fn (array $exception): array => [
                'date' => $exception['date'],
                'works' => filter_var($exception['works'], FILTER_VALIDATE_BOOL),
            ], $exceptions)),
            'lock_version' => $this->number('lock_version'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'exceptions.*.date.distinct' => 'На одну дату можно задать только одно исключение.',
            'exceptions.*.date.date_format' => 'Укажите дату исключения.',
            'exceptions.max' => 'Слишком много исключений.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'weekdays' => 'рабочие дни',
            'exceptions.*.date' => 'дата исключения',
        ];
    }
}
