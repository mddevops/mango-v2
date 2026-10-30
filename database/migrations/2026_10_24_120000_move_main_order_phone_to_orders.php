<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The main phone of an order moves from `order_phones` (the row at position 0) to
 * `orders.phone`; `order_phones` keeps only the additional numbers (positions 1…n).
 *
 * MySQL commits every DDL statement, so the steps are written to be re-run after a
 * failure: the column and index are added only when missing, only orders without
 * `orders.phone` are filled, the old rows are deleted only after the copy is verified,
 * and NOT NULL comes last. Source data is checked before anything is changed; on an
 * ambiguous order the migration stops and names order ids and problems only.
 *
 * Plain query builder updates: no model events, no audit, `updated_at` untouched.
 */
return new class extends Migration
{
    private const BATCH = 1000;

    private const NORMALIZED = '^7[0-9]{10}$';

    private const REPORTED_IDS = 50;

    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'phone')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('phone', 15)->nullable()->after('contact_name');
            });
        }

        if (! Schema::hasIndex('orders', 'orders_phone_index')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index('phone');
            });
        }

        $this->ensureNoAnomalies();

        $this->eachOrderRange(fn (int $from, int $to) => DB::table('orders')
            ->join('order_phones', fn ($join) => $join->on('order_phones.order_id', '=', 'orders.id')->where('order_phones.position', '=', 0))
            ->whereBetween('orders.id', [$from, $to])
            ->whereNull('orders.phone')
            ->update(['orders.phone' => DB::raw('order_phones.phone')]));

        $this->verifyCopy();

        $this->eachOrderRange(fn (int $from, int $to) => DB::table('order_phones')
            ->join('orders', 'orders.id', '=', 'order_phones.order_id')
            ->where('order_phones.position', 0)
            ->whereColumn('order_phones.phone', 'orders.phone')
            ->whereBetween('order_phones.order_id', [$from, $to])
            ->delete());

        if (DB::table('order_phones')->where('position', 0)->exists()) {
            throw new RuntimeException('Перенос основного телефона: в order_phones остались записи основного номера. Повторите миграцию.');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('phone', 15)->nullable(false)->change();
        });
    }

    /**
     * Restores the main rows from `orders.phone` first; the column is dropped only when
     * every order has its main row again.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'phone')) {
            return;
        }

        $this->eachOrderRange(fn (int $from, int $to) => DB::table('order_phones')->insertUsing(
            ['order_id', 'phone', 'name', 'position'],
            DB::table('orders')
                ->select(['orders.id', 'orders.phone', DB::raw('null'), DB::raw('0')])
                ->whereBetween('orders.id', [$from, $to])
                ->whereNotNull('orders.phone')
                ->whereNotExists(fn (Builder $main) => $main->selectRaw('1')->from('order_phones as main')
                    ->whereColumn('main.order_id', 'orders.id')
                    ->where('main.position', 0)),
        ));

        $missing = DB::table('orders')
            ->whereNotExists(fn (Builder $main) => $main->selectRaw('1')->from('order_phones')
                ->whereColumn('order_phones.order_id', 'orders.id')
                ->where('order_phones.position', 0))
            ->count();

        if ($missing > 0) {
            throw new RuntimeException("Откат основного телефона: у {$missing} заявок не восстановлена основная запись в order_phones. Колонка orders.phone не удалена.");
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->dropColumn('phone');
        });
    }

    /**
     * Orders still waiting for the copy must have exactly one main row, and every stored
     * number must be in the normalized form; nothing is guessed or substituted.
     */
    private function ensureNoAnomalies(): void
    {
        $pending = fn (Builder $query) => $query->whereNull('orders.phone');

        $problems = [
            'нет основного номера' => DB::table('orders')
                ->tap($pending)
                ->whereNotExists(fn (Builder $main) => $main->selectRaw('1')->from('order_phones')
                    ->whereColumn('order_phones.order_id', 'orders.id')
                    ->where('order_phones.position', 0))
                ->orderBy('orders.id'),
            'несколько основных номеров' => DB::table('orders')
                ->tap($pending)
                ->join('order_phones', 'order_phones.order_id', '=', 'orders.id')
                ->where('order_phones.position', 0)
                ->groupBy('orders.id')
                ->havingRaw('count(*) > 1')
                ->orderBy('orders.id'),
            'номер не нормализуется' => DB::table('orders')
                ->join('order_phones', 'order_phones.order_id', '=', 'orders.id')
                ->whereRaw('order_phones.phone not regexp ?', [self::NORMALIZED])
                ->distinct()
                ->orderBy('orders.id'),
        ];

        $report = [];

        foreach ($problems as $problem => $query) {
            $ids = $query->limit(self::REPORTED_IDS + 1)->pluck('orders.id')->all();

            if ($ids !== []) {
                $more = count($ids) > self::REPORTED_IDS ? ' и другие' : '';
                $report[] = "{$problem}: заявки ".implode(', ', array_slice($ids, 0, self::REPORTED_IDS)).$more;
            }
        }

        if ($report !== []) {
            throw new RuntimeException("Перенос основного телефона остановлен, данные не изменены. Исправьте заявки и повторите миграцию.\n".implode("\n", $report));
        }
    }

    private function verifyCopy(): void
    {
        $empty = DB::table('orders')->whereNull('phone')->count();
        $different = DB::table('orders')
            ->join('order_phones', 'order_phones.order_id', '=', 'orders.id')
            ->where('order_phones.position', 0)
            ->whereColumn('order_phones.phone', '!=', 'orders.phone')
            ->count();

        if ($empty > 0 || $different > 0) {
            throw new RuntimeException("Перенос основного телефона не подтверждён: без номера — {$empty}, расхождений — {$different}. Исходные записи не удалены.");
        }
    }

    /**
     * @param  callable(int, int): mixed  $step
     */
    private function eachOrderRange(callable $step): void
    {
        $max = (int) DB::table('orders')->max('id');

        for ($from = 1; $from <= $max; $from += self::BATCH) {
            $step($from, $from + self::BATCH - 1);
        }
    }
};
