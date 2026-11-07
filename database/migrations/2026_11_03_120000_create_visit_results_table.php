<?php

use App\Support\CodeGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Visit results of orders (consultation types): an editable reference chosen when the
 * client has arrived. A status asks for it through `requires_visit_result`.
 */
return new class extends Migration
{
    private const VALUES = ['Консультация нал', 'Консультация кредит', 'Заявка отказ', 'Заявка ФССП', 'Заявка решение', 'Продажа нал'];

    /** The legacy «Приехал» status, found by its immutable code. */
    private const ARRIVED = 'priehal';

    public function up(): void
    {
        Schema::create('visit_results', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['sort_order', 'id']);
        });

        $now = now();

        DB::table('visit_results')->insert(array_map(fn (string $name, int $index) => [
            'code' => CodeGenerator::base($name, 'visit'),
            'name' => $name,
            'sort_order' => ($index + 1) * 10,
            'active' => true,
            'lock_version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::VALUES, array_keys(self::VALUES)));

        Schema::table('order_statuses', function (Blueprint $table) {
            $table->boolean('requires_visit_result')->default(false)->after('requires_arrival_at');
        });

        DB::table('order_statuses')->where('code', self::ARRIVED)->where('is_initial', false)->update([
            'requires_arrival_at' => true,
            'requires_visit_result' => true,
        ]);

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('visit_result_id')->nullable()->after('arrival_at')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visit_result_id');
        });

        Schema::table('order_statuses', function (Blueprint $table) {
            $table->dropColumn('requires_visit_result');
        });

        Schema::dropIfExists('visit_results');
    }
};
