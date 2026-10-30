<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\Organization\Concerns\ReadsValidatedValues;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    use ReadsValidatedValues;

    /**
     * @return array{name: string, email: string, password: string, active: bool, role_ids: list<int>, showroom_ids: list<int>, department_ids: list<int>}
     */
    public function createData(): array
    {
        return [
            'name' => $this->text('name'),
            'email' => $this->text('email'),
            'password' => $this->text('password'),
            'active' => $this->validated('active') === null ? true : $this->boolean('active'),
            'role_ids' => $this->idList('role_ids'),
            'showroom_ids' => $this->idList('showroom_ids'),
            'department_ids' => $this->idList('department_ids'),
        ];
    }

    /**
     * @return array{name: string, email: string, lock_version: int, role_ids: list<int>, showroom_ids: list<int>, department_ids: list<int>}
     */
    public function updateData(): array
    {
        return [
            'name' => $this->text('name'),
            'email' => $this->text('email'),
            'lock_version' => $this->number('lock_version'),
            'role_ids' => $this->idList('role_ids'),
            'showroom_ids' => $this->idList('showroom_ids'),
            'department_ids' => $this->idList('department_ids'),
        ];
    }

    public function authorize(): bool
    {
        $user = $this->route('user');

        return (bool) $this->user()?->can($user ? 'update' : 'create', $user ?? User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->email) ? mb_strtolower(trim($this->email)) : $this->email,
            'role_ids' => $this->input('role_ids', []),
            'showroom_ids' => $this->input('showroom_ids', []),
            'department_ids' => $this->input('department_ids', []),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User|null $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($target?->id)],
            ...($target === null ? [
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
                'active' => ['sometimes', 'boolean'],
            ] : [
                'lock_version' => ['required', 'integer', 'min:0'],
            ]),
            'role_ids' => ['present', 'array', 'max:50'],
            'role_ids.*' => ['integer', 'distinct'],
            'showroom_ids' => ['present', 'array', 'max:500'],
            'showroom_ids.*' => ['integer', 'distinct'],
            'department_ids' => ['present', 'array', 'max:500'],
            'department_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'имя',
            'email' => 'email',
            'password' => 'пароль',
            'role_ids' => 'роли',
            'showroom_ids' => 'салоны',
            'department_ids' => 'отделы',
        ];
    }
}
