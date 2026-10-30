<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The client's name is optional: an order may be taken with a phone number only.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('orders')->whereNull('contact_name')->update(['contact_name' => '']);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('contact_name')->nullable(false)->change();
        });
    }
};
