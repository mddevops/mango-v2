<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved connection settings only: nothing here is called or received yet.
 *
 * One account (for example a Mango VPBX account) is stored once with its secrets and
 * serves any number of sources and showrooms. A line belongs to a source binding and
 * is unique within its account, so one account never routes a line two ways.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->string('verification_status', 30)->default('unverified');
            $table->text('secrets')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['provider', 'name']);
            $table->index(['provider', 'active']);
        });

        Schema::create('integration_connection_showroom', function (Blueprint $table) {
            $table->foreignId('integration_connection_id')
                ->constrained('integration_connections', 'id', 'integration_connection_showroom_connection_foreign')
                ->cascadeOnDelete();
            $table->foreignId('showroom_id')->constrained()->restrictOnDelete();

            $table->primary(['integration_connection_id', 'showroom_id'], 'integration_connection_showroom_primary');
        });

        Schema::create('integration_connection_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained()->restrictOnDelete();
            $table->string('provider', 50);
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['integration_connection_id', 'source_id'], 'integration_connection_sources_connection_source_unique');
            $table->unique(['source_id', 'provider'], 'integration_connection_sources_source_provider_unique');
            $table->unique(['id', 'integration_connection_id'], 'integration_connection_sources_id_connection_unique');
        });

        Schema::create('integration_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_connection_source_id');
            $table->unsignedBigInteger('integration_connection_id');
            $table->string('type', 10);
            $table->string('value', 100);
            $table->string('match_key', 100);
            $table->timestamps();

            $table->foreign(['integration_connection_source_id', 'integration_connection_id'], 'integration_lines_binding_foreign')
                ->references(['id', 'integration_connection_id'])
                ->on('integration_connection_sources')
                ->cascadeOnDelete();
            $table->unique(['integration_connection_id', 'type', 'match_key'], 'integration_lines_connection_line_unique');
        });

        Schema::create('source_site_intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->string('verification_status', 30)->default('unverified');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_site_intakes');
        Schema::dropIfExists('integration_lines');
        Schema::dropIfExists('integration_connection_sources');
        Schema::dropIfExists('integration_connection_showroom');
        Schema::dropIfExists('integration_connections');
    }
};
