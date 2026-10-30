<?php

namespace App\Http\Requests\Sources;

use App\Http\Requests\Organization\Concerns\ReadsValidatedValues;
use App\Models\IntegrationConnection;
use App\Models\IntegrationLine;
use App\Models\Source;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SourceRequest extends FormRequest
{
    use ReadsValidatedValues;

    public const MAX_LINES = 50;

    /**
     * @return array{showroom_id: int, source_kind_id: int, source_classification_id: int|null, agency_id: int|null, name: string, url: string|null, note: string|null, channel_ids: list<int>}
     */
    public function sourceData(): array
    {
        return [
            'showroom_id' => $this->number('showroom_id'),
            'source_kind_id' => $this->number('source_kind_id'),
            'source_classification_id' => $this->validated('source_classification_id') === null ? null : $this->number('source_classification_id'),
            'agency_id' => $this->validated('agency_id') === null ? null : $this->number('agency_id'),
            'name' => $this->text('name'),
            'url' => $this->nullableText('url'),
            'note' => $this->nullableText('note'),
            'channel_ids' => array_values(array_unique($this->idList('channel_ids'))),
        ];
    }

    /**
     * @return array{showroom_id: int, source_kind_id: int, source_classification_id: int|null, agency_id: int|null, name: string, url: string|null, note: string|null, channel_ids: list<int>, lock_version: int}
     */
    public function updateData(): array
    {
        return [...$this->sourceData(), 'lock_version' => $this->number('lock_version')];
    }

    /**
     * Connection bindings are sent only by users who manage connections; everyone else
     * saves the source without touching them.
     *
     * @return array{site_intake: bool, mango_connection_id: int|null, mango_lines: list<array{type: string, value: string}>}|null
     */
    public function connectionData(): ?array
    {
        if (! $this->has('connections')) {
            return null;
        }

        $lines = $this->validated('connections.mango_lines');
        $connectionId = $this->validated('connections.mango_connection_id');

        return [
            'site_intake' => $this->boolean('connections.site_intake'),
            'mango_connection_id' => is_numeric($connectionId) ? (int) $connectionId : null,
            'mango_lines' => $connectionId === null || ! is_array($lines) ? [] : array_values(array_map(fn (array $line) => [
                'type' => (string) $line['type'],
                'value' => trim((string) $line['value']),
            ], $lines)),
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();
        $source = $this->route('source');

        if ($user === null || ! $user->can($source ? 'update' : 'create', $source ?? Source::class)) {
            return false;
        }

        return ! $this->has('connections') || $user->can('viewAny', IntegrationConnection::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'code' => ['prohibited'],
            'showroom_id' => ['required', 'integer', 'exists:showrooms,id'],
            'source_kind_id' => ['required', 'integer', 'exists:source_kinds,id'],
            'source_classification_id' => ['nullable', 'integer', 'exists:source_classifications,id'],
            'agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:2048', 'url:http,https', $this->withoutCredentials(...)],
            'note' => ['nullable', 'string', 'max:2000'],
            'channel_ids' => ['present', 'array'],
            'channel_ids.*' => ['integer', 'distinct', 'exists:contact_channels,id'],
            'connections' => ['sometimes', 'array'],
            'connections.site_intake' => ['required_with:connections', 'boolean'],
            'connections.mango_connection_id' => ['nullable', 'integer', 'exists:integration_connections,id'],
            'connections.mango_lines' => ['nullable', 'array', 'max:'.self::MAX_LINES],
            'connections.mango_lines.*.type' => ['required', 'string', Rule::in(IntegrationLine::TYPES)],
            'connections.mango_lines.*.value' => ['required', 'string', 'max:100'],
        ];

        if ($this->route('source') !== null) {
            $rules['lock_version'] = ['required', 'integer', 'min:0'];
        }

        return $rules;
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $lines = $this->input('connections.mango_lines');

            if ($validator->errors()->isNotEmpty() || ! is_array($lines)) {
                return;
            }

            $seen = [];

            foreach (array_values($lines) as $index => $line) {
                $type = (string) ($line['type'] ?? '');
                $value = trim((string) ($line['value'] ?? ''));
                $field = "connections.mango_lines.{$index}.value";

                if ($type === IntegrationLine::PHONE) {
                    $digits = strlen(preg_replace('/\D+/', '', $value) ?? '');

                    if (preg_match('/^\+?[\d\s()\-]+$/', $value) !== 1 || $digits < 5 || $digits > 15) {
                        $validator->errors()->add($field, 'Укажите номер телефона: от 5 до 15 цифр.');

                        continue;
                    }
                } elseif (preg_match('/^\S+$/u', $value) !== 1) {
                    $validator->errors()->add($field, 'SIP-адрес не должен содержать пробелов.');

                    continue;
                }

                $key = $type.':'.IntegrationLine::matchKey($type, $value);

                if (isset($seen[$key])) {
                    $validator->errors()->add($field, 'Эта линия уже указана выше.');
                }

                $seen[$key] = true;
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'showroom_id' => 'салон',
            'source_kind_id' => 'вид источника',
            'source_classification_id' => 'классификация',
            'agency_id' => 'агентство',
            'name' => 'название',
            'url' => 'адрес',
            'note' => 'примечание',
            'channel_ids' => 'каналы обращения',
            'channel_ids.*' => 'канал обращения',
            'connections.mango_connection_id' => 'подключение Mango VPBX',
            'connections.mango_lines' => 'линии',
            'connections.mango_lines.*.type' => 'тип линии',
            'connections.mango_lines.*.value' => 'линия',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.prohibited' => 'Технический код создаётся автоматически и не может быть задан или изменён.',
            'url.url' => 'Укажите адрес, начинающийся с http:// или https://.',
        ];
    }

    private function withoutCredentials(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;

        if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
            $fail('Адрес не должен содержать логин или пароль.');
        }
    }
}
