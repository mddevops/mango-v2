<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The colour of the reference icon: a tinted square and a matching glyph (App\Enums\IconColor).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['source_kinds', 'contact_channels'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('icon_color', 16)->default('gray')->after('show_icon');
            });
        }
    }

    public function down(): void
    {
        foreach (['source_kinds', 'contact_channels'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('icon_color');
            });
        }
    }
};
