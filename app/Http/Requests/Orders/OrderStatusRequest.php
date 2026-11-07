<?php

namespace App\Http\Requests\Orders;

use App\Enums\OrderStatusColor;
use App\Http\Requests\Organization\Concerns\ReadsValidatedValues;
use App\Models\OrderStatus;
use App\Support\OrderReasonType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @phpstan-type OrderStatusData array{name: string, description: string|null, color: string, show_as_tab?: bool, requires_callback_at: bool, requires_arrival_at: bool, requires_visit_result?: bool, required_reason: string|null}
 */
class OrderStatusRequest extends FormRequest
{
    use ReadsValidatedValues;

    /**
     * @return OrderStatusData
     */
    public function createData(): array
    {
        return [
            'name' => $this->text('name'),
            'description' => $this->nullableText('description'),
            'color' => $this->text('color'),
            ...($this->has('show_as_tab') ? ['show_as_tab' => $this->boolean('show_as_tab')] : []),
            'requires_callback_at' => $this->boolean('requires_callback_at'),
            'requires_arrival_at' => $this->boolean('requires_arrival_at'),
            ...($this->has('requires_visit_result') ? ['requires_visit_result' => $this->boolean('requires_visit_result')] : []),
            'required_reason' => $this->nullableText('required_reason'),
        ];
    }

    /**
     * @return OrderStatusData&array{lock_version: int}
     */
    public function updateData(): array
    {
        return [...$this->createData(), 'lock_version' => $this->number('lock_version')];
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('order-references.manage');
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'description'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $id = $this->route('id');

        $rules = [
            'code' => ['prohibited'],
            'is_initial' => ['prohibited'],
            'name' => ['required', 'string', 'max:100', Rule::unique('order_statuses', 'name')->ignore(is_numeric($id) ? (int) $id : null)],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', Rule::enum(OrderStatusColor::class)],
            'show_as_tab' => ['sometimes', 'boolean'],
            'requires_callback_at' => ['required', 'boolean'],
            'requires_arrival_at' => ['required', 'boolean'],
            'requires_visit_result' => ['sometimes', 'boolean'],
            'required_reason' => ['nullable', Rule::enum(OrderReasonType::class)],
        ];

        if ($id !== null) {
            $rules['lock_version'] = ['required', 'integer', 'min:0'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'название',
            'description' => 'описание',
            'color' => 'цвет',
            'show_as_tab' => 'вкладка в списке заявок',
            'requires_callback_at' => 'требование даты перезвона',
            'requires_arrival_at' => 'требование даты приезда',
            'requires_visit_result' => 'требование итога визита',
            'required_reason' => 'справочник причин',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.prohibited' => 'Технический код создаётся автоматически и не может быть задан или изменён.',
            'is_initial.prohibited' => 'Начальный статус выбирается отдельным действием.',
            'name.unique' => OrderStatus::NAME_TAKEN,
            'color.enum' => 'Выберите цвет из палитры.',
            'required_reason.enum' => 'Выберите причины корзины или причины отказа / срыва.',
        ];
    }
}
