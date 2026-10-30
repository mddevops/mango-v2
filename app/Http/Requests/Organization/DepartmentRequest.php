<?php

namespace App\Http\Requests\Organization;

use App\Enums\DepartmentKind;
use App\Http\Requests\Organization\Concerns\ReadsValidatedValues;
use App\Models\Department;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepartmentRequest extends FormRequest
{
    use ReadsValidatedValues;

    /**
     * @return array{showroom_id: int|null, name: string, kind: string}
     */
    public function createData(): array
    {
        return [
            'showroom_id' => $this->validated('showroom_id') === null ? null : $this->number('showroom_id'),
            'name' => $this->text('name'),
            'kind' => $this->text('kind'),
        ];
    }

    /**
     * @return array{name: string, lock_version: int}
     */
    public function updateData(): array
    {
        return [
            'name' => $this->text('name'),
            'lock_version' => $this->number('lock_version'),
        ];
    }

    public function authorize(): bool
    {
        $department = $this->route('department');

        return (bool) $this->user()?->can($department ? 'update' : 'create', $department ?? Department::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('department') === null) {
            $this->merge([
                'showroom_id' => $this->filled('showroom_id') ? $this->showroom_id : null,
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->route('department') !== null) {
            return [
                'code' => ['prohibited'],
                'showroom_id' => ['prohibited'],
                'name' => ['required', 'string', 'max:255'],
                'lock_version' => ['required', 'integer', 'min:0'],
            ];
        }

        return [
            'code' => ['prohibited'],
            'showroom_id' => ['nullable', 'integer', 'exists:showrooms,id'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(DepartmentKind::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'showroom_id' => 'салон',
            'code' => 'код',
            'name' => 'название',
            'kind' => 'тип отдела',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.prohibited' => 'Технический код создаётся автоматически и не может быть задан или изменён.',
            'showroom_id.prohibited' => 'Перенос отдела в другой салон не поддерживается.',
        ];
    }
}
