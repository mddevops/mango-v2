<?php

namespace App\Http\Requests\Sources;

use App\Enums\IconColor;
use App\Http\Requests\Organization\Concerns\ReadsValidatedValues;
use App\Support\SourceReferenceType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SourceReferenceRequest extends FormRequest
{
    use ReadsValidatedValues;

    public function type(): SourceReferenceType
    {
        $type = $this->route('type');

        return $type instanceof SourceReferenceType ? $type : SourceReferenceType::from((string) $type);
    }

    /**
     * @return array{name: string, icon?: string|null, show_icon?: bool, icon_color?: IconColor, classification_ids?: list<int>, channel_ids?: list<int>}
     */
    public function createData(): array
    {
        $data = [
            'name' => $this->text('name'),
        ];

        if ($this->type()->hasIcon()) {
            $icon = $this->validated('icon');
            $data['icon'] = is_string($icon) && $icon !== '' ? $icon : null;
            $data['show_icon'] = $data['icon'] !== null && $this->boolean('show_icon');
            $data['icon_color'] = IconColor::tryFrom($this->text('icon_color')) ?? IconColor::Gray;
        }

        if ($this->type() === SourceReferenceType::Kinds) {
            $data['classification_ids'] = array_values(array_unique($this->idList('classification_ids')));
            $data['channel_ids'] = array_values(array_unique($this->idList('channel_ids')));
        }

        return $data;
    }

    /**
     * @return array{name: string, lock_version: int, icon?: string|null, show_icon?: bool, icon_color?: IconColor, classification_ids?: list<int>, channel_ids?: list<int>}
     */
    public function updateData(): array
    {
        return [...$this->createData(), 'lock_version' => $this->number('lock_version')];
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('source-references.manage');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = $this->type();
        $id = $this->route('id');

        $rules = [
            'code' => ['prohibited'],
            'name' => ['required', 'string', 'max:'.($type === SourceReferenceType::Agencies ? 255 : 100), Rule::unique($type->table(), 'name')->ignore(is_numeric($id) ? (int) $id : null)],
        ];

        if ($type->hasIcon()) {
            $rules['icon'] = ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'];
            $rules['show_icon'] = ['sometimes', 'boolean'];
            $rules['icon_color'] = ['nullable', Rule::enum(IconColor::class)];
        }

        if ($type === SourceReferenceType::Kinds) {
            $rules['classification_ids'] = ['present', 'array'];
            $rules['classification_ids.*'] = ['integer', 'distinct'];
            $rules['channel_ids'] = ['present', 'array'];
            $rules['channel_ids.*'] = ['integer', 'distinct'];
        }

        if ($id !== null) {
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
            'code' => 'код',
            'name' => 'название',
            'icon' => 'иконка',
            'show_icon' => 'показ иконки в таблице заявок',
            'icon_color' => 'цвет иконки',
            'classification_ids' => 'разрешённые классификации',
            'channel_ids' => 'разрешённые каналы',
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
            'icon.regex' => 'Укажите название иконки Lucide латиницей через дефис, например smartphone-charging.',
        ];
    }
}
