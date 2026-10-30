<?php

namespace App\Http\Requests\Support;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * A message to support with up to ten files: photos, videos, PDF, text and Word documents.
 * File size is not limited by the application, only by the server upload settings.
 */
class SupportMessageRequest extends FormRequest
{
    public const MAX_FILES = 10;

    public const FILE_TYPES = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif',
        'mp4', 'mov', 'avi', 'webm', 'mkv', '3gp', 'm4v',
        'pdf', 'txt', 'doc', 'docx',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function body(): string
    {
        $value = $this->validated('body');

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<UploadedFile>
     */
    public function attachments(): array
    {
        $files = $this->file('files', []);

        return array_values(array_filter(is_array($files) ? $files : [], fn ($file) => $file instanceof UploadedFile));
    }

    protected function prepareForValidation(): void
    {
        foreach (['subject', 'body'] as $key) {
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
        return [
            'body' => ['required', 'string', 'max:10000'],
            'files' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'mimes:'.implode(',', self::FILE_TYPES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['subject' => 'тема', 'body' => 'сообщение', 'files' => 'файлы', 'files.*' => 'файл'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.max' => 'Можно прикрепить не больше '.self::MAX_FILES.' файлов.',
            'files.*.mimes' => 'Можно прикрепить только фото, видео, PDF, TXT или Word.',
            'files.*.uploaded' => 'Файл не загрузился: он больше, чем разрешает сервер.',
        ];
    }
}
