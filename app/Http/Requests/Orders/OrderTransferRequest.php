<?php

namespace App\Http\Requests\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\OrderTransferManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @phpstan-import-type TransferData from OrderTransferManager
 */
class OrderTransferRequest extends FormRequest
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

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('transfer', $this->order());
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('comment'))) {
            $comment = trim($this->input('comment'));
            $this->merge(['comment' => $comment === '' ? null : $comment]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'showroom_id' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'operation_id' => ['required', 'uuid'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return TransferData
     */
    public function transferData(): array
    {
        $comment = $this->validated('comment');

        return [
            'showroom_id' => (int) $this->validated('showroom_id'),
            'comment' => is_string($comment) ? $comment : null,
            'operation_id' => strtolower((string) $this->validated('operation_id')),
            'lock_version' => (int) $this->validated('lock_version'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'showroom_id' => 'салон-получатель',
            'comment' => 'комментарий',
        ];
    }
}
