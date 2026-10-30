<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support requests from employees. Read state is kept as the id of the last message each
 * side has seen, so "unread" never depends on two events landing in the same second.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->string('subject', 200);
            $table->timestamp('last_message_at');
            $table->unsignedBigInteger('last_author_message_id')->nullable();
            $table->unsignedBigInteger('last_staff_message_id')->nullable();
            $table->unsignedBigInteger('author_read_message_id')->nullable();
            $table->unsignedBigInteger('staff_read_message_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['author_id', 'deleted_at', 'last_message_at']);
            $table->index(['deleted_at', 'last_message_at']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->boolean('from_staff');
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index(['support_ticket_id', 'from_staff', 'id']);
        });

        Schema::create('support_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_message_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
