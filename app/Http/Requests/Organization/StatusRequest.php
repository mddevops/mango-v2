<?php

namespace App\Http\Requests\Organization;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return match ($this->route()?->getName()) {
            'showrooms.status' => (bool) $user?->can('update', $this->route('showroom')),
            'departments.status' => (bool) $user?->can('update', $this->route('department')),
            'users.status' => (bool) $user?->can('changeStatus', $this->route('user')),
            'users.global-access' => (bool) $user?->can('grantGlobalAccess', $this->route('user')),
            'sources.status' => (bool) $user?->can('update', $this->route('source')),
            'source-references.status' => (bool) $user?->can('source-references.manage'),
            'order-statuses.status', 'order-reasons.status', 'payment-methods.status', 'visit-results.status' => (bool) $user?->can('order-references.manage'),
            'connections.status' => (bool) $user?->can('update', $this->route('connection')),
            default => false,
        };
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
