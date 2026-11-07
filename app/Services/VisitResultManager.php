<?php

namespace App\Services;

use App\Models\VisitResult;
use App\Services\Concerns\HandlesUniqueConstraints;
use App\Support\CodeGenerator;
use Illuminate\Support\Facades\DB;

class VisitResultManager
{
    use HandlesUniqueConstraints;

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(string $name): VisitResult
    {
        return $this->withUniqueField('visit_results_name_unique', 'name', VisitResult::NAME_TAKEN, fn () => $this->withGeneratedCode('visit_results_code_unique', fn () => DB::transaction(function () use ($name) {
            $base = CodeGenerator::base($name, 'visit');
            $taken = VisitResult::query()->where('code', 'like', CodeGenerator::prefix($base).'%')->pluck('code');

            $result = VisitResult::create([
                'code' => CodeGenerator::firstFree($base, $taken),
                'name' => $name,
                'sort_order' => ReferenceOrder::next(VisitResult::class),
            ]);

            $this->audit->record(VisitResult::AUDIT_SUBJECT.'.created', VisitResult::AUDIT_SUBJECT, $result->id, $result->name, VisitResult::SINGULAR.' создан', [
                'code' => $result->code,
                'name' => $result->name,
            ]);

            return $result;
        })));
    }

    public function update(int $id, string $name, int $lockVersion): VisitResult
    {
        return $this->withUniqueField('visit_results_name_unique', 'name', VisitResult::NAME_TAKEN, fn () => DB::transaction(function () use ($id, $name, $lockVersion) {
            $locked = VisitResult::query()->lockForUpdate()->findOrFail($id);
            $locked->ensureLockVersion($lockVersion);
            $locked->name = $name;

            if (! $locked->isDirty()) {
                return $locked;
            }

            $before = $locked->getOriginal('name');
            $locked->bumpLockVersion()->save();

            $this->audit->record(VisitResult::AUDIT_SUBJECT.'.updated', VisitResult::AUDIT_SUBJECT, $locked->id, $locked->name, VisitResult::SINGULAR.' сохранён', [
                'name' => [$before, $locked->name],
            ]);

            return $locked;
        }));
    }

    public function setActive(int $id, bool $active, mixed $lockVersion): VisitResult
    {
        return DB::transaction(function () use ($id, $active, $lockVersion) {
            $locked = VisitResult::query()->lockForUpdate()->findOrFail($id);
            $locked->ensureLockVersion($lockVersion);

            if ($locked->active === $active) {
                return $locked;
            }

            $locked->active = $active;
            $locked->bumpLockVersion()->save();

            $this->audit->record(
                VisitResult::AUDIT_SUBJECT.($active ? '.activated' : '.deactivated'),
                VisitResult::AUDIT_SUBJECT,
                $locked->id,
                $locked->name,
                VisitResult::SINGULAR.($active ? ' включён' : ' отключён'),
                ['active' => [! $active, $active]],
            );

            return $locked;
        });
    }
}
