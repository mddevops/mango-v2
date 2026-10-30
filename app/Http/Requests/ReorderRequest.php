<?php

namespace App\Http\Requests;

use App\Http\Requests\Organization\Concerns\ReadsValidatedValues;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderRequest extends FormRequest
{
    use ReadsValidatedValues;

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return $this->idList('ids');
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return match ($this->route()?->getName()) {
            'source-references.reorder' => (bool) $user?->can('source-references.manage'),
            'order-statuses.reorder', 'order-reasons.reorder' => (bool) $user?->can('order-references.manage'),
            default => false,
        };
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:2', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }
}
