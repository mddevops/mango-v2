<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\Organization\Concerns\ReadsValidatedValues;
use App\Models\Order;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Services\AccessScope;
use App\Services\OrderManager;
use App\Support\PermissionCatalog as P;
use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Manual order creation and editing. The showroom of an order comes from the source, which
 * cannot change after creation; a showroom chosen on creation is only checked against the
 * source (it is optional when the employee has one showroom). Phones come as
 * `phones[i][number|name]`: the first is the main number, the others are additional
 * numbers that may carry the name of their owner.
 *
 * @phpstan-import-type CreateData from OrderManager
 * @phpstan-import-type UpdateData from OrderManager
 */
class OrderRequest extends FormRequest
{
    use ReadsValidatedValues;

    public const MAX_EXTRA_PHONES = 5;

    private ?Order $order = null;

    /**
     * The order from the route, found within the employee's scope; an order outside it
     * answers 404 so its existence is not revealed.
     */
    public function order(): Order
    {
        if ($this->order === null) {
            /** @var User $user */
            $user = $this->user();

            $this->order = app(AccessScope::class)->orders(Order::query(), $user)->findOrFail($this->route('order'));
        }

        return $this->order;
    }

    public function isUpdate(): bool
    {
        return $this->route('order') !== null;
    }

    /**
     * Without a right to choose, the creator becomes responsible (no `responsible_id`).
     *
     * @return CreateData
     */
    public function createData(): array
    {
        return [
            'showroom_id' => $this->validated('showroom_id') === null ? null : $this->number('showroom_id'),
            'source_id' => $this->number('source_id'),
            'contact_channel_id' => $this->number('contact_channel_id'),
            'payment_method_id' => $this->paymentMethodId(),
            'contact_name' => $this->nullableText('contact_name'),
            ...$this->phoneData(),
            'car_interest' => $this->nullableText('car_interest'),
            'order_status_id' => $this->validated('order_status_id') === null ? null : $this->number('order_status_id'),
            'callback_at' => $this->nullableText('callback_at'),
            'arrival_at' => $this->nullableText('arrival_at'),
            'visit_result_id' => $this->visitResultId(),
            'reason_id' => $this->validated('reason_id') === null ? null : $this->number('reason_id'),
            'reason_comment' => $this->nullableText('reason_comment'),
            'comment' => $this->nullableText('comment'),
            ...$this->responsibleData(),
        ];
    }

    /**
     * @return UpdateData
     */
    public function updateData(): array
    {
        return [
            'contact_channel_id' => $this->number('contact_channel_id'),
            'payment_method_id' => $this->paymentMethodId(),
            'contact_name' => $this->nullableText('contact_name'),
            ...$this->phoneData(),
            'car_interest' => $this->nullableText('car_interest'),
            'order_status_id' => $this->number('order_status_id'),
            'callback_at' => $this->nullableText('callback_at'),
            'arrival_at' => $this->nullableText('arrival_at'),
            'visit_result_id' => $this->visitResultId(),
            'reason_id' => $this->validated('reason_id') === null ? null : $this->number('reason_id'),
            'reason_comment' => $this->nullableText('reason_comment'),
            'lock_version' => $this->number('lock_version'),
            ...$this->responsibleData(),
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $this->isUpdate() ? $user->can('update', $this->order()) : $user->can('create', Order::class);
    }

    protected function prepareForValidation(): void
    {
        foreach (['contact_name', 'car_interest', 'reason_comment', 'comment'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }

        $phones = $this->input('phones');

        if (! is_array($phones)) {
            return;
        }

        $clean = [];

        foreach (array_values($phones) as $index => $phone) {
            $number = is_array($phone) && is_string($phone['number'] ?? null) ? trim($phone['number']) : ($phone['number'] ?? null);
            $name = is_array($phone) && is_string($phone['name'] ?? null) ? trim($phone['name']) : null;

            if ($index > 0 && ($number === null || $number === '')) {
                continue;
            }

            $clean[] = ['number' => $number, 'name' => $index === 0 || $name === '' ? null : $name];
        }

        $this->merge(['phones' => $clean]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $common = [
            'showroom_id' => ['prohibited'],
            'contact_channel_id' => ['required', 'integer'],
            'payment_method_id' => ['nullable', 'integer'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'phones' => ['required', 'array', 'min:1', 'max:'.(self::MAX_EXTRA_PHONES + 1)],
            'phones.*' => ['array'],
            'phones.*.number' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || Phone::normalize($value) === null) {
                    $fail(Phone::INVALID);
                }
            }],
            'phones.*.name' => ['nullable', 'string', 'max:100'],
            'car_interest' => ['nullable', 'string', 'max:2000'],
            'responsible_id' => $this->canAssign()
                ? ($this->isUpdate() ? ['sometimes', 'nullable', 'integer'] : ['required', 'integer'])
                : ['prohibited'],
        ];

        $schedule = [
            'callback_at' => ['nullable', 'date_format:'.OrderManager::DATETIME_FORMAT],
            'arrival_at' => ['nullable', 'date_format:'.OrderManager::DATETIME_FORMAT],
            'visit_result_id' => ['nullable', 'integer'],
            'reason_id' => ['nullable', 'integer'],
            'reason_comment' => ['nullable', 'string', 'max:2000'],
        ];

        if (! $this->isUpdate()) {
            return [
                ...$common,
                ...$schedule,
                'showroom_id' => ['required', 'integer'],
                'source_id' => ['required', 'integer'],
                'order_status_id' => ['nullable', 'integer'],
                'comment' => $this->user()?->can(P::ORDERS_COMMENT) ? ['nullable', 'string', 'max:5000'] : ['prohibited'],
            ];
        }

        return [
            ...$common,
            ...$schedule,
            'source_id' => ['prohibited'],
            'order_status_id' => ['required', 'integer'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $phones = $this->phoneData()['phones'];

            if (count($phones) !== count(array_unique($phones))) {
                $validator->errors()->add('phones', 'Один и тот же номер указан несколько раз.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'showroom_id' => 'автосалон',
            'source_id' => 'источник',
            'contact_channel_id' => 'тип заявки',
            'payment_method_id' => 'способ оплаты',
            'contact_name' => 'имя',
            'phones' => 'телефоны',
            'phones.*.number' => 'телефон',
            'phones.*.name' => 'имя владельца номера',
            'car_interest' => 'интерес к автомобилю',
            'order_status_id' => 'статус',
            'callback_at' => 'дата перезвона',
            'arrival_at' => 'дата приезда',
            'visit_result_id' => 'итог визита',
            'reason_id' => 'причина',
            'reason_comment' => 'пояснение к причине',
            'responsible_id' => 'ответственный',
            'comment' => 'комментарий',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'showroom_id.prohibited' => 'Салон определяется источником и не задаётся вручную.',
            'showroom_id.required' => 'Выберите автосалон.',
            'source_id.required' => 'Выберите источник.',
            'source_id.prohibited' => 'Источник заявки нельзя изменить после создания.',
            'contact_channel_id.required' => 'Выберите тип заявки.',
            'responsible_id.required' => 'Выберите ответственного.',
            'responsible_id.prohibited' => 'Нет права назначать ответственного.',
            'comment.prohibited' => 'Нет права добавлять комментарии.',
            'phones.required' => 'Укажите основной телефон.',
            'phones.*.number.required' => 'Укажите номер телефона.',
            'phones.max' => 'Можно добавить не больше '.self::MAX_EXTRA_PHONES.' дополнительных телефонов.',
        ];
    }

    /**
     * @return array{phones: list<string>, phone_names: array<string, string|null>}
     */
    private function phoneData(): array
    {
        $phones = [];
        $names = [];
        $validated = $this->validated('phones');

        foreach (is_array($validated) ? $validated : [] as $phone) {
            $number = is_array($phone) && is_string($phone['number'] ?? null) ? Phone::normalize($phone['number']) : null;

            if ($number === null) {
                continue;
            }

            $phones[] = $number;
            $names[$number] ??= is_string($phone['name'] ?? null) ? $phone['name'] : null;
        }

        return ['phones' => $phones, 'phone_names' => $names];
    }

    private function paymentMethodId(): ?int
    {
        return $this->validated('payment_method_id') === null ? null : $this->number('payment_method_id');
    }

    private function visitResultId(): ?int
    {
        return $this->validated('visit_result_id') === null ? null : $this->number('visit_result_id');
    }

    /**
     * @return array{responsible_id?: int|null, assign_any?: bool}
     */
    private function responsibleData(): array
    {
        if (! $this->canAssign() || ! array_key_exists('responsible_id', $this->validated())) {
            return [];
        }

        return [
            'responsible_id' => $this->validated('responsible_id') === null ? null : $this->number('responsible_id'),
            'assign_any' => (bool) $this->user()?->can(P::ORDERS_ASSIGN_ANY),
        ];
    }

    private function canAssign(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $this->isUpdate() ? $user->can('assign', $this->order()) : OrderPolicy::canAssign($user);
    }
}
