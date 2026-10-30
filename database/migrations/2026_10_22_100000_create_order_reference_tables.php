<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order statuses and the two reason lists (trash and drop). Initial values are added
 * by OrderReferenceDataSeeder; legacy ids are not stored and define no behaviour.
 * `initial_marker` is 1 only for the initial status, so the unique index allows at
 * most one of them.
 */
return new class extends Migration
{
    private const REASON_TABLES = [
        'order_trash_reasons' => 'order_trash_reason',
        'order_drop_reasons' => 'order_drop_reason',
    ];

    public function up(): void
    {
        Schema::create('order_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100)->unique();
            $table->string('description', 1000)->nullable();
            $table->string('color', 16)->default('gray');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->boolean('is_initial')->default(false);
            $table->boolean('initial_marker')->nullable()->storedAs('(case when is_initial then 1 end)')->unique();
            $table->boolean('requires_callback_at')->default(false);
            $table->boolean('requires_arrival_at')->default(false);
            $table->string('required_reason', 16)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['sort_order', 'id']);
        });

        foreach (self::REASON_TABLES as $name => $singular) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('name', 100)->unique();
                $table->string('description', 1000)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('active')->default(true)->index();
                $table->boolean('comment_required')->default(false);
                $table->boolean('all_showrooms')->default(true);
                $table->unsignedInteger('lock_version')->default(0);
                $table->timestamps();

                $table->index(['sort_order', 'id']);
            });

            Schema::create($singular.'_showroom', function (Blueprint $pivot) use ($name, $singular) {
                $pivot->foreignId($singular.'_id')->constrained($name)->cascadeOnDelete();
                $pivot->foreignId('showroom_id')->constrained()->restrictOnDelete();

                $pivot->primary([$singular.'_id', 'showroom_id'], $singular.'_showroom_primary');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE order_statuses ADD CONSTRAINT order_statuses_initial_active_check CHECK (is_initial = 0 OR active = 1)');
            DB::statement("ALTER TABLE order_statuses ADD CONSTRAINT order_statuses_required_reason_check CHECK (required_reason IS NULL OR required_reason IN ('trash', 'drop'))");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::REASON_TABLES, true) as $name => $singular) {
            Schema::dropIfExists($singular.'_showroom');
            Schema::dropIfExists($name);
        }

        Schema::dropIfExists('order_statuses');
    }
};
