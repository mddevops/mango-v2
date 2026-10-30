<?php

namespace Tests\Feature\Distribution;

use App\Models\DistributionSchedule;
use App\Models\Showroom;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsOrganization;
use Tests\TestCase;

/**
 * Runs the schedule migration back and forth on the test database: DDL commits
 * implicitly, so truncation replaces the wrapping transaction.
 */
class ScheduleMigrationTest extends TestCase
{
    use BuildsOrganization;
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_each_served_showroom_gets_an_independent_copy_of_the_former_schedule(): void
    {
        $this->seedRoles();
        $north = Showroom::factory()->create();
        $south = Showroom::factory()->create();
        $other = Showroom::factory()->create();
        $served = $this->administrator(['name' => 'Обслуживал два салона']);
        $idle = $this->administrator(['name' => 'Не участвовал']);

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_22_200000_move_distribution_schedules_to_showrooms.php');
        $migration->down();

        DB::table('distribution_members')->insert([
            'user_id' => $served->id,
            'participates' => true,
            'monday' => true,
            'wednesday' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('distribution_member_showrooms')->insert([
            ['user_id' => $served->id, 'showroom_id' => $north->id],
            ['user_id' => $served->id, 'showroom_id' => $south->id],
        ]);
        DB::table('distribution_last_assignments')->insert(['user_id' => $served->id, 'showroom_id' => $north->id, 'assigned_at' => '2026-10-20 07:00:00']);
        DB::table('distribution_schedule_exceptions')->insert(['user_id' => $served->id, 'date' => '2026-10-24', 'works' => true]);
        DB::table('permissions')->insert(['name' => 'distribution_log.view', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);

        $migration->up();

        $this->assertFalse(Schema::hasTable('distribution_members'));
        $this->assertFalse(Schema::hasTable('distribution_decisions'));
        $this->assertDatabaseMissing('permissions', ['name' => 'distribution_log.view']);
        $this->assertSame(0, DistributionSchedule::query()->whereIn('user_id', [$idle->id])->count());
        $this->assertFalse(DistributionSchedule::query()->where('showroom_id', $other->id)->exists());

        $schedules = DistributionSchedule::query()->where('user_id', $served->id)->orderBy('showroom_id')->with('exceptions')->get();
        $this->assertSame([$north->id, $south->id], $schedules->pluck('showroom_id')->all());

        foreach ($schedules as $schedule) {
            $this->assertTrue($schedule->participates);
            $this->assertSame([true, false, true, false, false, false, false], $schedule->weekdays());
            $this->assertSame(['2026-10-24'], $schedule->exceptions->pluck('date')->all());
        }

        $this->assertSame('2026-10-20 07:00:00', $schedules[0]->last_assigned_at?->format('Y-m-d H:i:s'));
        $this->assertNull($schedules[1]->last_assigned_at);

        $this->actingAs($served)->put(route('distribution.schedules.update', [$north->id, $served->id]), [
            'participates' => false,
            'weekdays' => [false, false, false, false, false, true, true],
            'exceptions' => [],
            'lock_version' => 0,
        ])->assertSessionHasNoErrors();

        $southSchedule = $schedules[1]->fresh(['exceptions']);
        $this->assertTrue($southSchedule->participates);
        $this->assertSame([true, false, true, false, false, false, false], $southSchedule->weekdays());
        $this->assertCount(1, $southSchedule->exceptions);
    }
}
