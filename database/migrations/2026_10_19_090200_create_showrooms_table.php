<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('showrooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('timezone', 64)->default('Europe/Moscow');
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('showrooms');
    }
};
