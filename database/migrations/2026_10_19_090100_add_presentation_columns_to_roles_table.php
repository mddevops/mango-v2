<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->string('label', 100)->nullable()->after('name');
            $table->boolean('is_system')->default(false)->after('guard_name');
            $table->unsignedInteger('lock_version')->default(0)->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->dropColumn(['label', 'is_system', 'lock_version']);
        });
    }
};
