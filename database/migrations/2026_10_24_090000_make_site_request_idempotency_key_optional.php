<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency-Key becomes optional: a request without it is stored with NULL and is
 * always a new request. The unique index source + key stays and ignores NULLs, so
 * keyed deliveries keep their idempotency. Existing receipts and keys are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_callback_requests', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('site_callback_requests')->whereNull('idempotency_key')->update(['idempotency_key' => DB::raw('receipt_id')]);

        Schema::table('site_callback_requests', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable(false)->change();
        });
    }
};
