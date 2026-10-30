<?php

namespace App\Http\Requests\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\AccessScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OrderCommentRequest extends FormRequest
{
    private ?Order $order = null;

    public function order(): Order
    {
        if ($this->order === null) {
            /** @var User $user */
            $user = $this->user();

            $this->order = app(AccessScope::class)->orders(Order::query(), $user)->findOrFail($this->route('order'));
        }

        return $this->order;
    }

    public function body(): string
    {
        $value = $this->validated('body');

        return is_string($value) ? $value : '';
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('comment', $this->order());
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['body' => 'комментарий'];
    }
}
