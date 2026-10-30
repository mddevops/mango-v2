<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Mango VPBX call intake (stage 9a).
 *
 * - `integration_connections.webhook_key`: the random part of the event address. It finds
 *   the connection; the request signature still has to match its key and salt.
 * - `telephony_extensions`: connection + extension → employee, unambiguous per connection.
 * - `mango_events`: every accepted event with its raw `json` string, saved before the 200
 *   answer. `dedup_key` makes a repeated delivery of the same event a no-op.
 * - `calls`: one row per connection + entry_id; the single lock of all events of a call.
 * - `call_legs`: legs (Mango call_id) of a call, each with its own seq.
 * - `orders.call_assigned_at`: the connection moment of the last answerer a call applied
 *   to the order; an older connection processed later does not override it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table) {
            $table->string('webhook_key', 64)->nullable()->unique()->after('verification_status');
        });

        foreach (DB::table('integration_connections')->whereNull('webhook_key')->pluck('id') as $id) {
            DB::table('integration_connections')->where('id', $id)->update(['webhook_key' => Str::random(40)]);
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('call_assigned_at')->nullable()->after('distribution_state');
        });

        Schema::create('telephony_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained()->cascadeOnDelete();
            $table->string('extension', 32);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['integration_connection_id', 'extension'], 'telephony_extensions_connection_extension_unique');
            $table->index('user_id');
        });

        Schema::create('mango_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->char('dedup_key', 64);
            $table->string('entry_id', 128);
            $table->string('call_id', 128)->nullable();
            $table->unsignedInteger('seq')->nullable();
            $table->longText('payload');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->string('state', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_connection_id', 'dedup_key'], 'mango_events_connection_dedup_unique');
            $table->index(['integration_connection_id', 'entry_id']);
            $table->index(['state', 'id']);
            $table->index(['integration_connection_id', 'received_at']);
        });

        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained()->restrictOnDelete();
            $table->string('entry_id', 128);
            $table->string('direction', 16)->nullable();
            $table->boolean('direction_final')->default(false);
            $table->boolean('technical')->default(false);
            $table->string('line_number', 128)->nullable();
            $table->foreignId('integration_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('showroom_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('client_number', 128)->nullable();
            $table->string('client_phone', 15)->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('order_created')->default(false);
            $table->string('match_state', 24)->default('pending');
            $table->boolean('needs_review')->default(false);
            $table->boolean('answerer_unresolved')->default(false);
            $table->boolean('answer_ambiguous')->default(false);
            $table->string('result', 16)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('talk_seconds')->nullable();
            $table->unsignedInteger('disconnect_reason')->nullable();
            $table->string('summary_extension', 32)->nullable();
            $table->timestamp('summary_received_at')->nullable();
            $table->string('answered_extension', 32)->nullable();
            $table->foreignId('answered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_connected_at')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_connection_id', 'entry_id'], 'calls_connection_entry_unique');
            $table->index(['integration_connection_id', 'needs_review', 'id']);
            $table->index(['order_id', 'started_at']);
        });

        Schema::create('call_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->string('mango_call_id', 128);
            $table->string('taken_from_call_id', 128)->nullable();
            $table->unsignedInteger('last_seq')->default(0);
            $table->string('state', 16)->nullable();
            $table->string('location', 16)->nullable();
            $table->string('from_extension', 32)->nullable();
            $table->string('from_number', 128)->nullable();
            $table->string('to_extension', 32)->nullable();
            $table->string('to_number', 128)->nullable();
            $table->string('line_number', 128)->nullable();
            $table->boolean('technical')->default(false);
            $table->string('answered_extension', 32)->nullable();
            $table->foreignId('answered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('appeared_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('disconnect_reason')->nullable();
            $table->timestamps();

            $table->unique(['call_id', 'mango_call_id'], 'call_legs_call_leg_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_legs');
        Schema::dropIfExists('calls');
        Schema::dropIfExists('mango_events');
        Schema::dropIfExists('telephony_extensions');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('call_assigned_at');
        });

        Schema::table('integration_connections', function (Blueprint $table) {
            $table->dropUnique(['webhook_key']);
            $table->dropColumn('webhook_key');
        });
    }
};
