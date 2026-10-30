<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Callback distribution: who takes part and in which showrooms, the weekly schedule with
 * date exceptions, the last automatic assignment per showroom, the waiting state of an
 * order and the journal of decisions. Nobody is enrolled automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_members', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->boolean('participates')->default(false);

            foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
                $table->boolean($day)->default(false);
            }

            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        Schema::create('distribution_member_showrooms', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('distribution_members', 'user_id')->cascadeOnDelete();
            $table->foreignId('showroom_id')->constrained()->restrictOnDelete();

            $table->primary(['user_id', 'showroom_id']);
            $table->index('showroom_id');
        });

        Schema::create('distribution_schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('distribution_members', 'user_id')->cascadeOnDelete();
            $table->date('date');
            $table->boolean('works');

            $table->unique(['user_id', 'date']);
        });

        Schema::create('distribution_last_assignments', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('showroom_id')->constrained()->restrictOnDelete();
            $table->timestamp('assigned_at');

            $table->primary(['user_id', 'showroom_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('distribution_state', 16)->nullable()->after('responsible_id');
            $table->string('distribution_reason')->nullable()->after('distribution_state');
            $table->unsignedInteger('distribution_attempts')->default(0)->after('distribution_reason');
            $table->timestamp('distribution_attempted_at')->nullable()->after('distribution_attempts');

            $table->index(['distribution_state', 'id']);
        });

        Schema::create('distribution_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('showroom_id')->constrained()->restrictOnDelete();
            $table->timestamp('attempted_at');
            $table->date('business_date');
            $table->string('result', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule', 32)->nullable();
            $table->string('reason');
            $table->json('candidates')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');

            $table->index(['order_id', 'id']);
            $table->index(['showroom_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_decisions');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['distribution_state', 'id']);
            $table->dropColumn(['distribution_state', 'distribution_reason', 'distribution_attempts', 'distribution_attempted_at']);
        });

        Schema::dropIfExists('distribution_last_assignments');
        Schema::dropIfExists('distribution_schedule_exceptions');
        Schema::dropIfExists('distribution_member_showrooms');
        Schema::dropIfExists('distribution_members');
    }
};
