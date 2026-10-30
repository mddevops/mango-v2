<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Participation, the weekly schedule, date exceptions and the last automatic assignment
 * become settings of one employee in one showroom. Every showroom an employee actually
 * served gets his former participation, weekly schedule and exceptions; nothing is
 * enabled for other showrooms or employees. The decision journal, the explanation and
 * attempt columns of orders and the journal permission are removed; only the waiting
 * state needed for a safe retry stays.
 */
return new class extends Migration
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    private const LOG_PERMISSION = 'distribution_log.view';

    public function up(): void
    {
        Schema::dropIfExists('distribution_decisions');

        DB::table('orders')->whereIn('distribution_state', ['assigned', 'manual'])->update(['distribution_state' => null]);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['distribution_reason', 'distribution_attempts', 'distribution_attempted_at']);
        });

        Schema::create('distribution_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('showroom_id')->constrained()->restrictOnDelete();
            $table->boolean('participates')->default(false);

            foreach (self::DAYS as $day) {
                $table->boolean($day)->default(false);
            }

            $table->timestamp('last_assigned_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'showroom_id']);
            $table->index(['showroom_id', 'participates']);
        });

        $now = now();
        $members = DB::table('distribution_members')->get()->keyBy('user_id');
        $lastAssigned = DB::table('distribution_last_assignments')->get()->keyBy(fn (object $row) => $row->user_id.':'.$row->showroom_id);
        $exceptions = DB::table('distribution_schedule_exceptions')->orderBy('date')->get()->groupBy('user_id');
        $served = DB::table('distribution_member_showrooms')->orderBy('user_id')->orderBy('showroom_id')->get();
        $copied = [];

        foreach ($served as $link) {
            $member = $members[$link->user_id];
            $row = [
                'user_id' => $link->user_id,
                'showroom_id' => $link->showroom_id,
                'participates' => (bool) $member->participates,
                'last_assigned_at' => $lastAssigned[$link->user_id.':'.$link->showroom_id]->assigned_at ?? null,
                'lock_version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach (self::DAYS as $day) {
                $row[$day] = (bool) $member->{$day};
            }

            $copied[] = ['id' => DB::table('distribution_schedules')->insertGetId($row), 'user_id' => $link->user_id];
        }

        Schema::drop('distribution_last_assignments');
        Schema::drop('distribution_schedule_exceptions');
        Schema::drop('distribution_member_showrooms');
        Schema::drop('distribution_members');

        Schema::create('distribution_schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_schedule_id');
            $table->date('date');
            $table->boolean('works');

            $table->foreign('distribution_schedule_id', 'distribution_schedule_exceptions_schedule_foreign')
                ->references('id')->on('distribution_schedules')->cascadeOnDelete();

            $table->unique(['distribution_schedule_id', 'date'], 'distribution_schedule_exceptions_date_unique');
        });

        foreach ($copied as $schedule) {
            foreach ($exceptions[$schedule['user_id']] ?? [] as $exception) {
                DB::table('distribution_schedule_exceptions')->insert([
                    'distribution_schedule_id' => $schedule['id'],
                    'date' => $exception->date,
                    'works' => (bool) $exception->works,
                ]);
            }
        }

        DB::table(config('permission.table_names.permissions', 'permissions'))->where('name', self::LOG_PERMISSION)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Restores the former structure. One employee had one schedule, so the first served
     * showroom's schedule and exceptions become his common ones.
     */
    public function down(): void
    {
        Schema::create('distribution_members', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->boolean('participates')->default(false);

            foreach (self::DAYS as $day) {
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

        Schema::create('distribution_last_assignments', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('showroom_id')->constrained()->restrictOnDelete();
            $table->timestamp('assigned_at');

            $table->primary(['user_id', 'showroom_id']);
        });

        $schedules = DB::table('distribution_schedules')->orderBy('user_id')->orderBy('id')->get();
        $exceptions = DB::table('distribution_schedule_exceptions')->get()->groupBy('distribution_schedule_id');
        $restored = [];

        foreach ($schedules as $schedule) {
            if (! isset($restored[$schedule->user_id])) {
                $row = ['user_id' => $schedule->user_id, 'participates' => (bool) $schedule->participates, 'created_at' => now(), 'updated_at' => now()];

                foreach (self::DAYS as $day) {
                    $row[$day] = (bool) $schedule->{$day};
                }

                DB::table('distribution_members')->insert($row);
                $restored[$schedule->user_id] = $exceptions[$schedule->id] ?? collect();
            }

            DB::table('distribution_member_showrooms')->insert(['user_id' => $schedule->user_id, 'showroom_id' => $schedule->showroom_id]);

            if ($schedule->last_assigned_at !== null) {
                DB::table('distribution_last_assignments')->insert(['user_id' => $schedule->user_id, 'showroom_id' => $schedule->showroom_id, 'assigned_at' => $schedule->last_assigned_at]);
            }
        }

        Schema::drop('distribution_schedule_exceptions');
        Schema::drop('distribution_schedules');

        Schema::create('distribution_schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('distribution_members', 'user_id')->cascadeOnDelete();
            $table->date('date');
            $table->boolean('works');

            $table->unique(['user_id', 'date']);
        });

        foreach ($restored as $userId => $rows) {
            foreach ($rows as $exception) {
                DB::table('distribution_schedule_exceptions')->insert(['user_id' => $userId, 'date' => $exception->date, 'works' => (bool) $exception->works]);
            }
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('distribution_reason')->nullable()->after('distribution_state');
            $table->unsignedInteger('distribution_attempts')->default(0)->after('distribution_reason');
            $table->timestamp('distribution_attempted_at')->nullable()->after('distribution_attempts');
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
};
