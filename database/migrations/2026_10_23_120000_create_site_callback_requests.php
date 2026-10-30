<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Callback requests from sites through the common API.
 *
 * - `source_site_intakes` gets the API key of its source: only a SHA-256 hash, the last
 *   characters for recognition and the issue time. The key itself is never stored.
 * - `site_callback_requests`: every accepted request is saved before the site gets its
 *   receipt. One row per source and Idempotency-Key, at most one order per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_site_intakes', function (Blueprint $table) {
            $table->char('key_hash', 64)->nullable()->unique()->after('verification_status');
            $table->string('key_hint', 8)->nullable()->after('key_hash');
            $table->timestamp('key_issued_at')->nullable()->after('key_hint');
        });

        Schema::create('site_callback_requests', function (Blueprint $table) {
            $table->id();
            $table->char('receipt_id', 26)->unique();
            $table->foreignId('source_id')->constrained()->restrictOnDelete();
            $table->foreignId('showroom_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_channel_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->char('fingerprint', 64);
            $table->string('name', 150)->nullable();
            $table->string('phone', 15);
            $table->text('comment')->nullable();
            $table->string('visitor_ip', 45)->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('form', 100)->nullable();
            $table->json('viewed_vehicles')->nullable();
            $table->timestamp('event_at');
            $table->boolean('event_time_reported');
            $table->timestamp('received_at');
            $table->date('business_date');
            $table->json('business_day_rule');
            $table->string('state', 16);
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'idempotency_key'], 'site_callback_requests_source_key_unique');
            $table->index(['state', 'id']);
            $table->index(['source_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_callback_requests');

        Schema::table('source_site_intakes', function (Blueprint $table) {
            $table->dropUnique(['key_hash']);
            $table->dropColumn(['key_hash', 'key_hint', 'key_issued_at']);
        });
    }
};
