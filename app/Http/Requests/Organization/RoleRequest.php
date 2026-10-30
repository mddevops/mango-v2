<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\Organization\Concerns\ReadsValidatedValues;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    use ReadsValidatedValues;

    /**
     * @return array{name: string, label: string, permissions: list<string>}
     */
    public function createData(): array
    {
        return [
            'name' => $this->text('name'),
            'label' => $this->text('label'),
            'permissions' => $this->textList('permissions'),
        ];
    }

    /**
     * @return array{label: string, permissions: list<string>, lock_version: int}
     */
    public function updateData(): array
    {
        return [
            'label' => $this->text('label'),
            'permissions' => $this->textList('permissions'),
            'lock_version' => $this->number('lock_version'),
        ];
    }

    public function authorize(): bool
    {
        $role = $this->route('role');

        return (bool) $this->user()?->can($role ? 'update' : 'create', $role ?? Role::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['permissions' => $this->input('permissions', [])]);

        if ($this->route('role') === null && is_string($this->name)) {
            $this->merge(['name' => mb_strtolower(trim($this->name))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...($this->route('role') === null ? [
                'name' => [
                    'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                    Rule::unique(config('permission.table_names.roles'), 'name')->where('guard_name', 'web'),
                ],
            ] : [
                'lock_version' => ['required', 'integer', 'min:0'],
            ]),
            'label' => ['required', 'string', 'max:100'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(PermissionCatalog::all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'код роли',
            'label' => 'название',
            'permissions' => 'разрешения',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Код роли: строчные латинские буквы, цифры и «_», начиная с буквы.',
        ];
    }
}
