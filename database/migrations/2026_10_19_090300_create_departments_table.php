<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('showroom_id')->nullable()->constrained()->restrictOnDelete();
            // MySQL UNIQUE treats NULLs as distinct, so shared departments (showroom_id = NULL)
            // get scope 0 through a stored generated column that the unique index can use.
            $table->unsignedBigInteger('scope_key')->storedAs('ifnull(`showroom_id`, 0)');
            $table->string('code', 50);
            $table->string('name');
            $table->string('kind', 32)->index();
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['scope_key', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
