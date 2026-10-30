<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final rule of the responsible (29.10.2026): Mango only fills an empty responsible and
 * never replaces one, so the order no longer needs the moment of the latest applied
 * connection. Instead each call decides once — `calls.assignment_decided_at` — whether
 * its answerer takes the order; a repeated event, job or rematch of a decided call never
 * assigns again, even after the responsible was removed by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->timestamp('assignment_decided_at')->nullable()->after('assigned_user_id');
        });

        DB::table('calls')->whereNotNull('assigned_user_id')->update(['assignment_decided_at' => DB::raw('updated_at')]);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('call_assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('call_assigned_at')->nullable()->after('distribution_state');
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('assignment_decided_at');
        });
    }
};
