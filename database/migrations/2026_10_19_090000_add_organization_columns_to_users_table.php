<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('password');
            $table->boolean('has_global_access')->default(false)->after('active');
            $table->unsignedInteger('lock_version')->default(0)->after('has_global_access');

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['active']);
            $table->dropColumn(['active', 'has_global_access', 'lock_version']);
        });
    }
};
