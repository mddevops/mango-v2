<?php

namespace App\Services;

use App\Enums\DepartmentKind;
use App\Models\Department;
use App\Models\Showroom;
use App\Support\CodeGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationManager
{
    /**
     * Codes are picked from committed rows, so two concurrent creations can choose the
     * same one; the unique index rejects the loser, which then retries with a fresh read.
     */
    private const CODE_ATTEMPTS = 5;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, address?: string|null, phone?: string|null, timezone: string, business_day_boundary_enabled?: bool|null, business_day_boundary?: string|null}  $data
     */
    public function createShowroom(array $data): Showroom
    {
        $base = CodeGenerator::base($data['name'], 'showroom');
        $data['business_day_boundary_enabled'] ??= true;
        $data['business_day_boundary'] ??= Showroom::DEFAULT_BUSINESS_DAY_BOUNDARY;

        return $this->withGeneratedCode('showrooms_code_unique', fn () => DB::transaction(function () use ($data, $base) {
            $taken = Showroom::query()->where('code', 'like', CodeGenerator::prefix($base).'%')->pluck('code');

            $showroom = Showroom::create([...$data, 'code' => CodeGenerator::firstFree($base, $taken)]);

            $this->audit->record('showroom.created', 'showroom', $showroom->id, $showroom->name, 'Создан салон', [
                'code' => $showroom->code,
                'name' => $showroom->name,
                'address' => $showroom->address,
                'phone' => $showroom->phone,
                'timezone' => $showroom->timezone,
                'business_day_boundary_enabled' => $showroom->business_day_boundary_enabled,
                'business_day_boundary' => $showroom->business_day_boundary,
            ], [$showroom->id]);

            return $showroom;
        }));
    }

    /**
     * A switched-off boundary keeps its time, so switching it back on restores it.
     *
     * @param  array{name: string, address?: string|null, phone?: string|null, timezone: string, business_day_boundary_enabled?: bool|null, business_day_boundary?: string|null, lock_version: int}  $data
     */
    public function updateShowroom(Showroom $showroom, array $data): Showroom
    {
        return DB::transaction(function () use ($showroom, $data) {
            /** @var Showroom $locked */
            $locked = Showroom::query()->lockForUpdate()->findOrFail($showroom->id);
            $locked->ensureLockVersion($data['lock_version']);

            $locked->fill([
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'timezone' => $data['timezone'],
                'business_day_boundary_enabled' => $data['business_day_boundary_enabled'] ?? $locked->business_day_boundary_enabled,
                'business_day_boundary' => $data['business_day_boundary'] ?? $locked->business_day_boundary,
            ]);

            $changes = $this->diff($locked);

            if ($changes === []) {
                return $locked;
            }

            $locked->bumpLockVersion()->save();

            $this->audit->record('showroom.updated', 'showroom', $locked->id, $locked->name, 'Изменён салон', $changes, [$locked->id]);

            return $locked;
        });
    }

    public function setShowroomActive(Showroom $showroom, bool $active, mixed $lockVersion): Showroom
    {
        return DB::transaction(function () use ($showroom, $active, $lockVersion) {
            /** @var Showroom $locked */
            $locked = Showroom::query()->lockForUpdate()->findOrFail($showroom->id);
            $locked->ensureLockVersion($lockVersion);

            if ($locked->active === $active) {
                return $locked;
            }

            $locked->active = $active;
            $locked->bumpLockVersion()->save();

            $this->audit->record(
                $active ? 'showroom.activated' : 'showroom.deactivated',
                'showroom',
                $locked->id,
                $locked->name,
                $active ? 'Салон активирован' : 'Салон деактивирован',
                ['active' => [! $active, $active]],
                [$locked->id],
            );

            return $locked;
        });
    }

    /**
     * @param  array{showroom_id: int|null, name: string, kind: string}  $data
     */
    public function createDepartment(array $data): Department
    {
        $kind = DepartmentKind::from($data['kind']);

        if ($kind->requiresShowroom() && $data['showroom_id'] === null) {
            throw ValidationException::withMessages(['showroom_id' => 'Отдел салона должен быть привязан к салону.']);
        }

        $base = CodeGenerator::base($data['name'], 'department');

        return $this->withGeneratedCode('departments_scope_key_code_unique', fn () => DB::transaction(function () use ($data, $base) {
            $taken = Department::query()
                ->where('code', 'like', CodeGenerator::prefix($base).'%')
                ->when(
                    $data['showroom_id'] === null,
                    fn ($query) => $query->whereNull('showroom_id'),
                    fn ($query) => $query->where('showroom_id', $data['showroom_id']),
                )
                ->pluck('code');

            $department = Department::create([...$data, 'code' => CodeGenerator::firstFree($base, $taken)]);
            $department->load('showroom');

            $this->audit->record('department.created', 'department', $department->id, $department->displayName(), 'Создан отдел', [
                'code' => $department->code,
                'name' => $department->name,
                'kind' => $department->kind->label(),
                'showroom' => $department->showroom->name ?? 'Общий',
            ], array_filter([$department->showroom_id]));

            return $department;
        }));
    }

    /**
     * @param  array{name: string, lock_version: int}  $data
     */
    public function updateDepartment(Department $department, array $data): Department
    {
        return DB::transaction(function () use ($department, $data) {
            /** @var Department $locked */
            $locked = Department::query()->with('showroom')->lockForUpdate()->findOrFail($department->id);
            $locked->ensureLockVersion($data['lock_version']);

            $locked->name = $data['name'];
            $changes = $this->diff($locked);

            if ($changes === []) {
                return $locked;
            }

            $locked->bumpLockVersion()->save();

            $this->audit->record('department.updated', 'department', $locked->id, $locked->displayName(), 'Изменён отдел', $changes, array_filter([$locked->showroom_id]));

            return $locked;
        });
    }

    public function setDepartmentActive(Department $department, bool $active, mixed $lockVersion): Department
    {
        return DB::transaction(function () use ($department, $active, $lockVersion) {
            /** @var Department $locked */
            $locked = Department::query()->with('showroom')->lockForUpdate()->findOrFail($department->id);
            $locked->ensureLockVersion($lockVersion);

            if ($locked->active === $active) {
                return $locked;
            }

            $locked->active = $active;
            $locked->bumpLockVersion()->save();

            $this->audit->record(
                $active ? 'department.activated' : 'department.deactivated',
                'department',
                $locked->id,
                $locked->displayName(),
                $active ? 'Отдел активирован' : 'Отдел деактивирован',
                ['active' => [! $active, $active]],
                array_filter([$locked->showroom_id]),
            );

            return $locked;
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withGeneratedCode(string $codeIndex, callable $callback): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback();
            } catch (UniqueConstraintViolationException $exception) {
                if (! str_contains($exception->getMessage(), $codeIndex)) {
                    throw $exception;
                }

                if ($attempt >= self::CODE_ATTEMPTS) {
                    throw ValidationException::withMessages([
                        'name' => 'Не удалось подобрать уникальный технический код. Повторите сохранение.',
                    ]);
                }
            }
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function diff(Showroom|Department $model): array
    {
        $changes = [];

        foreach ($model->getDirty() as $field => $value) {
            $changes[$field] = [$model->getOriginal($field), $value];
        }

        return $changes;
    }
}
