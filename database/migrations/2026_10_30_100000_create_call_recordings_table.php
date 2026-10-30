<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mango call recordings (stage 9b).
 *
 * One row per connection + Mango `recording_id`: the id is unique within one VPBX
 * (3.1.4), nothing is promised across accounts. `call_id` is filled once the call with
 * the same entry_id is known; a recording never creates a call or an order. The file
 * lives on the private `recordings` disk; neither a Mango link nor a key is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained()->restrictOnDelete();
            $table->string('recording_id', 128);
            $table->string('entry_id', 128);
            $table->string('mango_call_id', 128)->nullable();
            $table->unsignedInteger('last_seq')->default(0);
            $table->string('extension', 32)->nullable();
            $table->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recording_state', 16)->nullable();
            $table->unsignedInteger('completion_code')->nullable();
            $table->string('recipient', 16)->nullable();
            $table->timestamp('added_at')->nullable();
            $table->string('state', 16);
            $table->timestamp('available_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->char('claim_token', 32)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('mango_result')->nullable();
            $table->string('file_path')->nullable();
            $table->string('mime_type', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_connection_id', 'recording_id'], 'call_recordings_connection_recording_unique');
            $table->index(['integration_connection_id', 'entry_id']);
            $table->index(['state', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_recordings');
    }
};
