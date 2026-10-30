<?php

namespace App\Services;

use App\Models\DistributionSchedule;
use App\Models\DistributionScheduleException;
use App\Models\Showroom;
use App\Models\User;
use App\Support\PermissionCatalog as P;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves the participation, weekly schedule and date exceptions of one employee in one
 * showroom. The editor needs access to that showroom; the employee must already have
 * access to it, and a schedule never grants any. Disabling participation only stops new
 * assignments; earlier orders keep their responsible. Other showrooms of the employee
 * are never touched.
 *
 * @phpstan-type ScheduleData array{participates: bool, weekdays: list<bool>, exceptions: list<array{date: string, works: bool}>, lock_version: int}
 */
class DistributionScheduleManager
{
    private const WEEKDAY_LABELS = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'];

    public const NO_ACCESS = 'У сотрудника нет доступа к этому салону: график задаётся только для салонов его области доступа.';

    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Employees that may take orders of the showroom and that the viewer may see: active
     * or not, with the right to view orders and access to the showroom.
     *
     * @return Builder<User>
     */
    public function employees(Showroom $showroom, User $viewer): Builder
    {
        return $this->scope->users(User::query(), $viewer)
            ->permission(P::ORDERS_VIEW)
            ->where(fn (Builder $access) => $access
                ->where('users.has_global_access', true)
                ->orWhereHas('showrooms', fn (Builder $showrooms) => $showrooms->where('showrooms.id', $showroom->id)));
    }

    public function canManage(User $viewer, Showroom $showroom): bool
    {
        return $viewer->can('distribution-schedules.manage') && $this->scope->canAccessShowroom($viewer, $showroom->id);
    }

    /**
     * @param  ScheduleData  $data
     */
    public function save(User $viewer, User $employee, Showroom $showroom, array $data): DistributionSchedule
    {
        $this->ensureAllowed($viewer, $employee, $showroom);

        return DB::transaction(function () use ($employee, $showroom, $data) {
            $schedule = $this->lockSchedule($employee, $showroom);
            $schedule->ensureLockVersion($data['lock_version']);
            $before = $this->snapshot($schedule);

            $schedule->participates = $data['participates'];
            $this->apply($schedule, $data['weekdays'], $data['exceptions']);

            return $this->finish($schedule, $before, $employee, $showroom, 'Изменены участие в распределении и график салона');
        });
    }

    /**
     * Copies the weekly schedule and the exceptions of the employee's other showroom into
     * this one. The copy is independent; participation of this showroom stays as it was.
     * The viewer needs access to both showrooms, so no other showroom's schedule leaks.
     */
    public function copy(User $viewer, User $employee, Showroom $showroom, Showroom $from, int $lockVersion): DistributionSchedule
    {
        $this->ensureAllowed($viewer, $employee, $showroom);

        if ($from->id === $showroom->id || ! $this->scope->canAccessShowroom($viewer, $from->id)) {
            throw ValidationException::withMessages(['from_showroom_id' => 'Выберите другой салон из вашей области доступа.']);
        }

        /** @var DistributionSchedule|null $origin */
        $origin = DistributionSchedule::query()->where('user_id', $employee->id)->where('showroom_id', $from->id)->with('exceptions')->first();

        if ($origin === null) {
            throw ValidationException::withMessages(['from_showroom_id' => 'В выбранном салоне у сотрудника нет графика.']);
        }

        return DB::transaction(function () use ($employee, $showroom, $from, $origin, $lockVersion) {
            $schedule = $this->lockSchedule($employee, $showroom);
            $schedule->ensureLockVersion($lockVersion);
            $before = $this->snapshot($schedule);

            $exceptions = $origin->exceptions->map(fn (DistributionScheduleException $exception) => ['date' => $exception->date, 'works' => $exception->works])->values()->all();
            $this->apply($schedule, $origin->weekdays(), $exceptions);

            return $this->finish($schedule, $before, $employee, $showroom, "График скопирован из салона «{$from->name}»");
        });
    }

    private function ensureAllowed(User $viewer, User $employee, Showroom $showroom): void
    {
        abort_unless($this->canManage($viewer, $showroom), 403);

        if (! $this->employees($showroom, $viewer)->whereKey($employee->id)->exists()) {
            throw ValidationException::withMessages(['participates' => self::NO_ACCESS]);
        }
    }

    private function lockSchedule(User $employee, Showroom $showroom): DistributionSchedule
    {
        DistributionSchedule::query()->insertOrIgnore([
            'user_id' => $employee->id,
            'showroom_id' => $showroom->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var DistributionSchedule $schedule */
        $schedule = DistributionSchedule::query()
            ->where('user_id', $employee->id)
            ->where('showroom_id', $showroom->id)
            ->lockForUpdate()
            ->firstOrFail();

        return $schedule->load('exceptions');
    }

    /**
     * @param  list<bool>  $weekdays
     * @param  list<array{date: string, works: bool}>  $exceptions
     */
    private function apply(DistributionSchedule $schedule, array $weekdays, array $exceptions): void
    {
        foreach (array_values(DistributionSchedule::WEEKDAYS) as $index => $column) {
            $schedule->setAttribute($column, $weekdays[$index] ?? false);
        }

        $schedule->bumpLockVersion()->save();
        $schedule->exceptions()->delete();

        foreach ($exceptions as $exception) {
            DistributionScheduleException::create(['distribution_schedule_id' => $schedule->id, 'date' => $exception['date'], 'works' => $exception['works']]);
        }
    }

    /**
     * @param  array<string, string|null>  $before
     */
    private function finish(DistributionSchedule $schedule, array $before, User $employee, Showroom $showroom, string $description): DistributionSchedule
    {
        $schedule->unsetRelations()->load('exceptions');
        $after = $this->snapshot($schedule);
        $changes = [];

        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = [$before[$field], $value];
            }
        }

        if ($changes !== []) {
            $this->audit->record('distribution.schedule_updated', 'distribution_schedule', $schedule->id, "{$employee->name} · {$showroom->name}", $description, $changes, [$showroom->id]);
        }

        return $schedule;
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshot(DistributionSchedule $schedule): array
    {
        $days = array_keys(array_filter($schedule->weekdays()));

        return [
            'participates' => $schedule->participates ? 'да' : 'нет',
            'weekdays' => implode(', ', array_map(fn (int $day) => self::WEEKDAY_LABELS[$day], $days)) ?: null,
            'exceptions' => $schedule->exceptions
                ->map(fn (DistributionScheduleException $exception) => $exception->date.' '.($exception->works ? 'работает' : 'выходной'))
                ->implode(', ') ?: null,
        ];
    }
}
