<?php

use App\Support\CodeGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A status may be shown as a quick tab above the orders list. The initial set is the
 * seeded statuses found by their technical code; an administrator changes it later in
 * the status directory. Statuses created or renamed under other codes stay without a tab.
 */
return new class extends Migration
{
    private const INITIAL_TABS = ['Новая', 'В работе', 'Не отвечает', 'Приедет', 'Приехал', 'Корзина'];

    public function up(): void
    {
        if (! Schema::hasColumn('order_statuses', 'show_as_tab')) {
            Schema::table('order_statuses', function (Blueprint $table) {
                $table->boolean('show_as_tab')->default(false)->after('sort_order');
            });
        }

        $codes = array_map(fn (string $name) => CodeGenerator::base($name, 'status'), self::INITIAL_TABS);

        DB::table('order_statuses')->whereIn('code', $codes)->update(['show_as_tab' => true]);
    }

    public function down(): void
    {
        Schema::table('order_statuses', function (Blueprint $table) {
            $table->dropColumn('show_as_tab');
        });
    }
};
