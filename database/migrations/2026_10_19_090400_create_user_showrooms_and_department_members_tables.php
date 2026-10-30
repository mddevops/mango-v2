<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_showrooms', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('showroom_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'showroom_id']);
            $table->index('showroom_id');
        });

        Schema::create('department_members', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['department_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_members');
        Schema::dropIfExists('user_showrooms');
    }
};
