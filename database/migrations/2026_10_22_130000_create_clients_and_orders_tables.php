<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('client_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('phone', 15)->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('showroom_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_channel_id')->constrained()->restrictOnDelete();
            $table->string('origin', 16);
            $table->string('contact_name');
            $table->foreignId('order_status_id')->constrained()->restrictOnDelete();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('callback_at')->nullable();
            $table->timestamp('arrival_at')->nullable();
            $table->text('car_interest')->nullable();
            $table->foreignId('order_trash_reason_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('order_drop_reason_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('reason_comment')->nullable();
            $table->timestamp('event_at');
            $table->timestamp('received_at');
            $table->date('business_date');
            $table->json('business_day_rule');
            $table->unsignedInteger('repeat_number')->default(0);
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'showroom_id', 'repeat_number'], 'orders_client_showroom_repeat_unique');
            $table->index(['showroom_id', 'business_date']);
            $table->index(['showroom_id', 'updated_at']);
        });

        DB::statement('alter table orders add constraint orders_single_reason_check check (order_trash_reason_id is null or order_drop_reason_id is null)');

        Schema::create('order_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 15)->index();
            $table->unsignedTinyInteger('position');

            $table->unique(['order_id', 'phone']);
        });

        Schema::create('order_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'id']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor_kind', 16)->default('user')->after('actor_name');
            $table->string('basis', 64)->nullable()->after('actor_kind');
        });

        DB::table('audit_logs')->whereNull('actor_id')->update(['actor_kind' => 'system', 'basis' => 'console']);
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['actor_kind', 'basis']);
        });

        Schema::dropIfExists('order_comments');
        Schema::dropIfExists('order_phones');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('client_phones');
        Schema::dropIfExists('clients');
    }
};
