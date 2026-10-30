<?php

namespace Database\Seeders;

use App\Enums\DepartmentKind;
use App\Models\ContactChannel;
use App\Models\Department;
use App\Models\SourceClassification;
use App\Models\SourceKind;
use App\Services\OrderTransferManager;
use Illuminate\Database\Seeder;

/**
 * Reference data that every installation needs. Existing records are left as they are,
 * so a renamed, reordered or disabled value keeps its state after a repeated run. The
 * default rules of a kind are applied only when the seeder creates that kind.
 */
class ReferenceDataSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, sort_order: int}>
     */
    private const CLASSIFICATIONS = [
        ['code' => 'advertising', 'name' => 'Реклама', 'sort_order' => 10],
        ['code' => 'seo', 'name' => 'SEO', 'sort_order' => 20],
        ['code' => 'advertising-seo', 'name' => 'Реклама/SEO', 'sort_order' => 30],
        ['code' => 'va', 'name' => 'VA', 'sort_order' => 50],
    ];

    /**
     * @var list<array{code: string, name: string, sort_order: int}>
     */
    private const CHANNELS = [
        ['code' => 'callback-request', 'name' => 'Обратный звонок', 'sort_order' => 10],
        ['code' => 'direct-call', 'name' => 'Прямой звонок', 'sort_order' => 20],
        ['code' => 'chat', 'name' => 'Чат', 'sort_order' => 30],
    ];

    private const ALL_CHANNELS = ['callback-request', 'direct-call', 'chat'];

    private const MARKETING = ['advertising', 'seo', 'advertising-seo'];

    /**
     * @var list<array{code: string, name: string, sort_order: int, classifications: list<string>, channels: list<string>}>
     */
    private const KINDS = [
        ['code' => 'site', 'name' => 'Сайт', 'sort_order' => 10, 'classifications' => self::MARKETING, 'channels' => self::ALL_CHANNELS],
        ['code' => 'quiz', 'name' => 'Квиз', 'sort_order' => 20, 'classifications' => self::MARKETING, 'channels' => ['callback-request']],
        ['code' => 'bot', 'name' => 'Бот', 'sort_order' => 30, 'classifications' => [], 'channels' => self::ALL_CHANNELS],
        ['code' => 'social', 'name' => 'Соцсеть', 'sort_order' => 40, 'classifications' => [], 'channels' => self::ALL_CHANNELS],
        ['code' => 'classified', 'name' => 'Классифайд', 'sort_order' => 50, 'classifications' => [], 'channels' => self::ALL_CHANNELS],
        ['code' => 'partner', 'name' => 'Партнёр', 'sort_order' => 60, 'classifications' => [], 'channels' => self::ALL_CHANNELS],
        ['code' => 'manual', 'name' => 'Ручной ввод', 'sort_order' => 70, 'classifications' => [], 'channels' => self::ALL_CHANNELS],
        ['code' => OrderTransferManager::KIND_CODE, 'name' => 'Другой салон', 'sort_order' => 80, 'classifications' => [], 'channels' => self::ALL_CHANNELS],
    ];

    public function run(): void
    {
        $exists = Department::query()
            ->whereNull('showroom_id')
            ->where('code', 'call_center')
            ->exists();

        if (! $exists) {
            Department::create([
                'showroom_id' => null,
                'code' => 'call_center',
                'name' => 'Колл-центр',
                'kind' => DepartmentKind::CallCenter->value,
            ]);
        }

        foreach (self::CLASSIFICATIONS as $row) {
            $this->createMissing(SourceClassification::class, $row);
        }

        foreach (self::CHANNELS as $row) {
            $this->createMissing(ContactChannel::class, $row);
        }

        foreach (self::KINDS as $row) {
            $kind = $this->createMissing(SourceKind::class, ['code' => $row['code'], 'name' => $row['name'], 'sort_order' => $row['sort_order']]);

            if ($kind instanceof SourceKind) {
                $kind->classifications()->sync(SourceClassification::query()->whereIn('code', $row['classifications'])->pluck('id')->all());
                $kind->channels()->sync(ContactChannel::query()->whereIn('code', $row['channels'])->pluck('id')->all());
            }
        }
    }

    /**
     * Creates the value unless one with the same code or name exists.
     *
     * @param  class-string<SourceClassification|ContactChannel|SourceKind>  $class
     * @param  array{code: string, name: string, sort_order: int}  $row
     * @return SourceClassification|ContactChannel|SourceKind|null the created value, or null when it already existed
     */
    private function createMissing(string $class, array $row): SourceClassification|ContactChannel|SourceKind|null
    {
        $exists = $class::query()
            ->where('code', $row['code'])
            ->orWhere('name', $row['name'])
            ->exists();

        return $exists ? null : $class::create($row);
    }
}
