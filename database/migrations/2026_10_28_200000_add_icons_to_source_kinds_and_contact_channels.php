<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Lucide icon name (smartphone-charging) for source kinds and contact channels, and
 * whether the orders list shows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['source_kinds', 'contact_channels'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('icon', 64)->nullable()->after('name');
                $table->boolean('show_icon')->default(false)->after('icon');
            });
        }
    }

    public function down(): void
    {
        foreach (['source_kinds', 'contact_channels'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['icon', 'show_icon']);
            });
        }
    }
};
