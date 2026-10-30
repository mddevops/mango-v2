<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An additional number may name the person it belongs to (wife, colleague);
     * the main number belongs to the order's contact.
     */
    public function up(): void
    {
        Schema::table('order_phones', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('order_phones', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
