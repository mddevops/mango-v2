<?php

namespace Database\Seeders;

use App\Enums\OrderStatusPurpose;
use App\Models\OrderDropReason;
use App\Models\OrderStatus;
use App\Models\OrderTrashReason;
use App\Support\CodeGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Initial order statuses and reasons taken from the legacy `order_statuses`,
 * `order_trashes` and `order_drops` (docs/legacy). Legacy ids are not kept and nothing
 * is linked to V2 showrooms by them. A value whose code or name already exists is left
 * as it is, so a repeated run never duplicates, renames, re-enables or reconfigures it.
 */
class OrderReferenceDataSeeder extends Seeder
{
    /**
     * In legacy id order, which also breaks ties of equal `order` values.
     *
     * @var list<array{name: string, active: bool, sort_order: int, tab?: bool, callback?: bool, arrival?: bool, reason?: string}>
     */
    private const STATUSES = [
        ['name' => 'Новая', 'active' => true, 'sort_order' => 1, 'tab' => true],
        ['name' => 'В работе', 'active' => true, 'sort_order' => 2, 'tab' => true],
        ['name' => 'Не отвечает', 'active' => true, 'sort_order' => 3, 'tab' => true],
        ['name' => 'Одобрить', 'active' => true, 'sort_order' => 4],
        ['name' => 'Приедет', 'active' => true, 'sort_order' => 6, 'tab' => true, 'arrival' => true],
        ['name' => 'Приехал', 'active' => true, 'sort_order' => 7, 'tab' => true, 'arrival' => true, 'visit_result' => true],
        ['name' => 'Корзина', 'active' => true, 'sort_order' => 8, 'tab' => true, 'reason' => 'trash'],
        ['name' => 'Повтор', 'active' => true, 'sort_order' => 9],
        ['name' => 'Одобренный', 'active' => true, 'sort_order' => 5],
        ['name' => 'Не ликвид', 'active' => true, 'sort_order' => 11],
        ['name' => 'Повтор ДИ', 'active' => true, 'sort_order' => 12],
        ['name' => 'ТО', 'active' => true, 'sort_order' => 10],
        ['name' => 'Перезвон', 'active' => true, 'sort_order' => 10, 'callback' => true],
        ['name' => 'Передано', 'active' => true, 'sort_order' => 13],
    ];

    private const INITIAL_STATUS = 'Новая';

    /** The seeded status the transfer to another showroom sets, linked by its purpose. */
    private const TRANSFERRED_STATUS = 'Передано';

    /**
     * Raw legacy names; edges are trimmed on insert. `legacy_showrooms` are ids of the
     * old project and are only quoted in the description.
     *
     * @var list<array{name: string, legacy_showrooms?: string}>
     */
    private const TRASH_REASONS = [
        ['name' => 'Не подавал'],
        ['name' => 'Наличка'],
        ['name' => 'Отказался'],
        ['name' => 'Долги ФССП'],
        ['name' => 'Не поедет в Москву', 'legacy_showrooms' => '1,2'],
        ['name' => "Отзывы\r\n"],
        ['name' => 'Нет гражданства'],
        ['name' => 'Реклама'],
        ['name' => 'Не поедет в Волгоград', 'legacy_showrooms' => '8'],
        ['name' => 'Повтор'],
        ['name' => 'Не дозвон'],
        ['name' => 'Другое'],
        ['name' => 'Некорректный регион'],
        ['name' => 'Не поедет в Нижний Новгород', 'legacy_showrooms' => '11'],
        ['name' => 'Некорректные данные'],
        ['name' => 'Далеко'],
        ['name' => 'Отложил покупку'],
        ['name' => 'Не поедет в Ростов', 'legacy_showrooms' => '2,4,10,14'],
        ['name' => 'Уже купил'],
    ];

    /**
     * @var list<array{name: string, legacy_showrooms?: string}>
     */
    private const DROP_REASONS = [
        ['name' => 'Отзывы'],
        ['name' => 'Изменился способ оплаты '],
        ['name' => 'Не отпустили с работы'],
        ['name' => 'Нашел дешевле'],
        ['name' => 'Не верит (Цена / Наличие)'],
        ['name' => 'Поедет к дилеру '],
        ['name' => 'Уже купил'],
        ['name' => 'Купил автотеку '],
        ['name' => 'Передумал покупать '],
        ['name' => 'Семейные обстоятельства '],
        ['name' => 'Нет денег'],
        ['name' => 'Не дозвон'],
        ['name' => 'Был у нас в салоне  либо есть в нашем салоне'],
        ['name' => 'Другое'],
    ];

    private const COMMENT_REQUIRED = ['Другое'];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::STATUSES as $row) {
                $this->createStatus($row);
            }

            if (! OrderStatus::query()->where('is_initial', true)->exists()) {
                OrderStatus::query()
                    ->where('code', CodeGenerator::base(self::INITIAL_STATUS, 'status'))
                    ->where('active', true)
                    ->update(['is_initial' => true]);
            }

            if (! OrderStatus::query()->where('purpose', OrderStatusPurpose::Transferred)->exists()) {
                OrderStatus::query()
                    ->where('code', CodeGenerator::base(self::TRANSFERRED_STATUS, 'status'))
                    ->where('is_initial', false)
                    ->update(['purpose' => OrderStatusPurpose::Transferred, 'active' => true]);
            }

            foreach ([OrderTrashReason::class => self::TRASH_REASONS, OrderDropReason::class => self::DROP_REASONS] as $class => $rows) {
                foreach ($rows as $index => $row) {
                    $this->createReason($class, $row, ($index + 1) * 10);
                }
            }
        });
    }

    /**
     * @param  array{name: string, active: bool, sort_order: int, tab?: bool, callback?: bool, arrival?: bool, visit_result?: bool, reason?: string}  $row
     */
    private function createStatus(array $row): void
    {
        $code = CodeGenerator::base($row['name'], 'status');

        if (OrderStatus::query()->where('code', $code)->orWhere('name', $row['name'])->exists()) {
            return;
        }

        $status = new OrderStatus([
            'code' => $code,
            'name' => $row['name'],
            'sort_order' => $row['sort_order'],
            'show_as_tab' => $row['tab'] ?? false,
            'requires_callback_at' => $row['callback'] ?? false,
            'requires_arrival_at' => $row['arrival'] ?? false,
            'requires_visit_result' => $row['visit_result'] ?? false,
            'required_reason' => $row['reason'] ?? null,
        ]);
        $status->active = $row['active'];
        $status->save();
    }

    /**
     * @param  class-string<OrderTrashReason|OrderDropReason>  $class
     * @param  array{name: string, legacy_showrooms?: string}  $row
     */
    private function createReason(string $class, array $row, int $sortOrder): void
    {
        $name = trim($row['name']);
        $code = CodeGenerator::base($name, 'reason');

        if ($class::query()->where('code', $code)->orWhere('name', $name)->exists()) {
            return;
        }

        $restricted = isset($row['legacy_showrooms']);

        $reason = new $class([
            'code' => $code,
            'name' => $name,
            'description' => $restricted
                ? 'Требуется настроить область применения. В старой CRM причина была ограничена салонами старой CRM (id: '
                    .str_replace(',', ', ', $row['legacy_showrooms']).'); с салонами V2 она не связана. Перед включением выберите все салоны или конкретные салоны.'
                : null,
            'sort_order' => $sortOrder,
            'comment_required' => in_array($name, self::COMMENT_REQUIRED, true),
            'all_showrooms' => ! $restricted,
        ]);
        $reason->active = ! $restricted;
        $reason->save();
    }
}
