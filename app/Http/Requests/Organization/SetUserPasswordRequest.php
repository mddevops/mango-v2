<?php

namespace App\Http\Requests\Organization;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('setPassword', $this->route('user'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['password' => 'пароль'];
    }
}
