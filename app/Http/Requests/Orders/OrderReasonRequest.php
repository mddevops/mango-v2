<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\Organization\Concerns\ReadsValidatedValues;
use App\Models\User;
use App\Services\AccessScope;
use App\Support\OrderReasonType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * @phpstan-type OrderReasonData array{name: string, description: string|null, comment_required: bool, all_showrooms: bool, showroom_ids: list<int>}
 */
class OrderReasonRequest extends FormRequest
{
    use ReadsValidatedValues;

    public function type(): OrderReasonType
    {
        $type = $this->route('type');

        return $type instanceof OrderReasonType ? $type : OrderReasonType::from((string) $type);
    }

    /**
     * Choosing every showroom drops the explicit list, so a reason has one scope only.
     *
     * @return OrderReasonData
     */
    public function createData(): array
    {
        $all = $this->boolean('all_showrooms');

        return [
            'name' => $this->text('name'),
            'description' => $this->nullableText('description'),
            'comment_required' => $this->boolean('comment_required'),
            'all_showrooms' => $all,
            'showroom_ids' => $all ? [] : array_values(array_unique($this->idList('showroom_ids'))),
        ];
    }

    /**
     * @return OrderReasonData&array{lock_version: int}
     */
    public function updateData(): array
    {
        return [...$this->createData(), 'lock_version' => $this->number('lock_version')];
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('order-references.manage');
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'description'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
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
            'name' => ['required', 'string', 'max:100', Rule::unique($this->type()->table(), 'name')->ignore(is_numeric($id) ? (int) $id : null)],
            'description' => ['nullable', 'string', 'max:1000'],
            'comment_required' => ['required', 'boolean'],
            'all_showrooms' => ['required', 'boolean'],
            'showroom_ids' => ['present', 'array'],
            'showroom_ids.*' => ['integer', 'distinct'],
        ];

        if ($id !== null) {
            $rules['lock_version'] = ['required', 'integer', 'min:0'];
        }

        return $rules;
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->boolean('all_showrooms')) {
                return;
            }

            /** @var User $user */
            $user = $this->user();
            $scope = app(AccessScope::class);

            foreach ($this->idList('showroom_ids') as $id) {
                if (! $scope->canAccessShowroom($user, $id)) {
                    $validator->errors()->add('showroom_ids', 'Можно выбрать только салоны из своей области доступа.');

                    return;
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'название',
            'description' => 'описание',
            'comment_required' => 'обязательный комментарий',
            'all_showrooms' => 'область применения',
            'showroom_ids' => 'салоны',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.prohibited' => 'Технический код создаётся автоматически и не может быть задан или изменён.',
            'name.unique' => $this->type()->nameTaken(),
        ];
    }
}
