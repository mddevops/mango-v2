<?php

use App\Support\CodeGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment methods of orders: an editable reference with the values of the legacy CRM.
 * An order may have none; values are disabled, never deleted.
 */
return new class extends Migration
{
    private const VALUES = ['Не определено', 'Наличными', 'В кредит', 'Лизинг', 'Не дозвон', 'Повтор', 'Не ликвид', 'ТО'];

    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
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

        DB::table('payment_methods')->insert(array_map(fn (string $name, int $index) => [
            'code' => CodeGenerator::base($name, 'payment'),
            'name' => $name,
            'sort_order' => ($index + 1) * 10,
            'active' => true,
            'lock_version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::VALUES, array_keys(self::VALUES)));

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('contact_channel_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
        });

        Schema::dropIfExists('payment_methods');
    }
};
