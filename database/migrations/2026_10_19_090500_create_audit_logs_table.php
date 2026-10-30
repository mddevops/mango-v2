<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('event', 64)->index();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label');
            $table->text('description');
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('audit_log_showroom', function (Blueprint $table) {
            $table->foreignId('audit_log_id')->constrained()->cascadeOnDelete();
            $table->foreignId('showroom_id')->constrained()->restrictOnDelete();

            $table->primary(['audit_log_id', 'showroom_id']);
            $table->index('showroom_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log_showroom');
        Schema::dropIfExists('audit_logs');
    }
};
