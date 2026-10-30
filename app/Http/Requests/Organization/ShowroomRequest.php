<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\Organization\Concerns\ReadsValidatedValues;
use App\Models\Showroom;
use App\Support\Timezones;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowroomRequest extends FormRequest
{
    use ReadsValidatedValues;

    public const TIME_FORMAT = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * Omitted business-day fields mean "leave as is": defaults on creation, the current
     * values on update.
     *
     * @return array{name: string, address: string|null, phone: string|null, timezone: string, business_day_boundary_enabled: bool|null, business_day_boundary: string|null}
     */
    public function createData(): array
    {
        return [
            'name' => $this->text('name'),
            'address' => $this->nullableText('address'),
            'phone' => $this->nullableText('phone'),
            'timezone' => $this->text('timezone'),
            'business_day_boundary_enabled' => $this->has('business_day_boundary_enabled') ? $this->boolean('business_day_boundary_enabled') : null,
            'business_day_boundary' => $this->nullableText('business_day_boundary'),
        ];
    }

    /**
     * @return array{name: string, address: string|null, phone: string|null, timezone: string, business_day_boundary_enabled: bool|null, business_day_boundary: string|null, lock_version: int}
     */
    public function updateData(): array
    {
        return [...$this->createData(), 'lock_version' => $this->number('lock_version')];
    }

    public function authorize(): bool
    {
        $showroom = $this->route('showroom');

        return (bool) $this->user()?->can($showroom ? 'update' : 'create', $showroom ?? Showroom::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'address' => $this->filled('address') ? trim((string) $this->address) : null,
            'phone' => $this->filled('phone') ? trim((string) $this->phone) : null,
            'business_day_boundary' => $this->filled('business_day_boundary') ? trim((string) $this->business_day_boundary) : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['prohibited'],
            ...($this->route('showroom') === null ? [] : [
                'lock_version' => ['required', 'integer', 'min:0'],
            ]),
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+()\-\s]{5,50}$/'],
            'timezone' => ['required', 'string', Rule::in($this->allowedTimezones())],
            'business_day_boundary_enabled' => ['sometimes', 'boolean'],
            'business_day_boundary' => ['nullable', 'required_if_accepted:business_day_boundary_enabled', 'string', 'regex:'.self::TIME_FORMAT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'код',
            'name' => 'название',
            'address' => 'адрес',
            'phone' => 'телефон',
            'timezone' => 'часовой пояс',
            'business_day_boundary_enabled' => 'граница расчётного дня',
            'business_day_boundary' => 'время границы',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.prohibited' => 'Технический код создаётся автоматически и не может быть задан или изменён.',
            'phone.regex' => 'Телефон может содержать только цифры, пробелы и символы + ( ) -.',
            'timezone.in' => 'Выберите часовой пояс из списка.',
            'business_day_boundary.required_if_accepted' => 'Укажите время границы расчётного дня.',
            'business_day_boundary.regex' => 'Укажите время в формате ЧЧ:ММ, от 00:00 до 23:59.',
        ];
    }

    /**
     * A showroom saved earlier with a zone outside the list can still be edited
     * without being forced to change it.
     *
     * @return list<string>
     */
    private function allowedTimezones(): array
    {
        $showroom = $this->route('showroom');
        $allowed = Timezones::values();

        if ($showroom instanceof Showroom && ! in_array($showroom->timezone, $allowed, true)) {
            $allowed[] = $showroom->timezone;
        }

        return $allowed;
    }
}
