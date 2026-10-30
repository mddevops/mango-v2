<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['sort_order', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_types');
    }
};
