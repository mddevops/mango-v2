<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\Organization\Concerns\ReadsValidatedValues;
use App\Models\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentMethodRequest extends FormRequest
{
    use ReadsValidatedValues;

    public function name(): string
    {
        return $this->text('name');
    }

    public function lockVersion(): int
    {
        return $this->number('lock_version');
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('order-references.manage');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
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
            'name' => ['required', 'string', 'max:100', Rule::unique('payment_methods', 'name')->ignore(is_numeric($id) ? (int) $id : null)],
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
        return ['name' => 'название'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.prohibited' => 'Технический код создаётся автоматически и не может быть задан или изменён.',
            'name.unique' => PaymentMethod::NAME_TAKEN,
        ];
    }
}
