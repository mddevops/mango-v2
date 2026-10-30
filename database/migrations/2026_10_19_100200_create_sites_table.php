<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('showroom_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('url', 2048)->nullable();
            $table->text('note')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['showroom_id', 'active']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
