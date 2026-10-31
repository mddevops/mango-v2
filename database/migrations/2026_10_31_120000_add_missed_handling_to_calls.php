<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who marked a missed call as handled and when. The call itself and its result stay as
 * Mango reported them; an unhandled missed call is one without this mark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->timestamp('missed_handled_at')->nullable()->after('result');
            $table->foreignId('missed_handled_by_id')->nullable()->after('missed_handled_at')->constrained('users')->nullOnDelete();
            $table->index(['order_id', 'result', 'missed_handled_at'], 'calls_order_missed_index');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex('calls_order_missed_index');
            $table->dropConstrainedForeignId('missed_handled_by_id');
            $table->dropColumn('missed_handled_at');
        });
    }
};
