<?php

namespace App\Http\Requests\Support;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A new support request: a subject plus the first message.
 */
class SupportTicketRequest extends SupportMessageRequest
{
    public function subject(): string
    {
        $value = $this->validated('subject');

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:200'],
            ...parent::rules(),
        ];
    }
}
