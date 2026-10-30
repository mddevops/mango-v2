<?php

namespace App\Http\Requests\Sources;

use App\Http\Requests\Organization\Concerns\ReadsValidatedValues;
use App\Models\IntegrationConnection;
use App\Support\IntegrationProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntegrationConnectionRequest extends FormRequest
{
    use ReadsValidatedValues;

    /**
     * @return array{name: string, showroom_ids: list<int>, secrets: array<string, string>, clear: list<string>}
     */
    public function connectionData(): array
    {
        $secrets = [];

        foreach (array_keys(IntegrationProvider::MangoVpbx->secretFields()) as $key) {
            $value = $this->validated($key);
            $secrets[$key] = is_string($value) ? trim($value) : '';
        }

        return [
            'name' => $this->text('name'),
            'showroom_ids' => array_values(array_unique($this->idList('showroom_ids'))),
            'secrets' => $secrets,
            'clear' => $this->textList('clear_secrets'),
        ];
    }

    /**
     * @return array{name: string, showroom_ids: list<int>, secrets: array<string, string>, clear: list<string>, lock_version: int}
     */
    public function updateData(): array
    {
        return [...$this->connectionData(), 'lock_version' => $this->number('lock_version')];
    }

    public function authorize(): bool
    {
        $user = $this->user();
        $connection = $this->route('connection');

        if ($user === null || ! $user->can($connection ? 'update' : 'create', $connection ?? IntegrationConnection::class)) {
            return false;
        }

        $touchesSecrets = $this->filled('api_key') || $this->filled('api_salt') || $this->filled('clear_secrets');

        return ! $touchesSecrets || $user->can('manageSecrets', IntegrationConnection::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $connection = $this->route('connection');
        $keys = array_keys(IntegrationProvider::MangoVpbx->secretFields());

        $rules = [
            'name' => ['required', 'string', 'max:255', Rule::unique('integration_connections', 'name')
                ->where('provider', IntegrationProvider::MangoVpbx->value)
                ->ignore($connection instanceof IntegrationConnection ? $connection->id : null)],
            'showroom_ids' => ['required', 'array', 'min:1'],
            'showroom_ids.*' => ['integer', 'distinct', 'exists:showrooms,id'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_salt' => ['nullable', 'string', 'max:255'],
            'clear_secrets' => ['nullable', 'array'],
            'clear_secrets.*' => ['string', Rule::in($keys)],
        ];

        if ($connection !== null) {
            $rules['lock_version'] = ['required', 'integer', 'min:0'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'название',
            'showroom_ids' => 'салоны',
            'api_key' => 'API key',
            'api_salt' => 'API salt',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Подключение с таким названием уже существует.',
            'showroom_ids.required' => 'Выберите хотя бы один салон.',
            'showroom_ids.min' => 'Выберите хотя бы один салон.',
        ];
    }
}
