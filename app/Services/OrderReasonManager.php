<?php

namespace App\Services;

use App\Http\Requests\Orders\OrderReasonRequest;
use App\Models\OrderDropReason;
use App\Models\OrderTrashReason;
use App\Models\Showroom;
use App\Services\Concerns\HandlesUniqueConstraints;
use App\Support\CodeGenerator;
use App\Support\OrderReasonType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-import-type OrderReasonData from OrderReasonRequest
 */
class OrderReasonManager
{
    use HandlesUniqueConstraints;

    public const SCOPE_REQUIRED = 'Выберите салоны или вариант «Все салоны».';

    private const AUDITED = ['name', 'description', 'comment_required', 'all_showrooms'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  OrderReasonData  $data
     */
    public function create(OrderReasonType $type, array $data): OrderTrashReason|OrderDropReason
    {
        return $this->withUniqueField($type->table().'_name_unique', 'name', $type->nameTaken(), fn () => $this->withGeneratedCode($type->table().'_code_unique', fn () => DB::transaction(function () use ($type, $data) {
            $class = $type->model();
            $base = CodeGenerator::base($data['name'], 'reason');
            $taken = $class::query()->where('code', 'like', CodeGenerator::prefix($base).'%')->pluck('code');

            if (! $data['all_showrooms'] && $data['showroom_ids'] === []) {
                throw ValidationException::withMessages(['showroom_ids' => self::SCOPE_REQUIRED]);
            }

            $reason = $class::create([
                ...collect($data)->only(self::AUDITED)->all(),
                'code' => CodeGenerator::firstFree($base, $taken),
                'sort_order' => ReferenceOrder::next($class),
            ]);

            $changes = ['code' => $reason->code];

            foreach (self::AUDITED as $field) {
                $changes[$field] = $reason->getAttribute($field);
            }

            $names = $this->syncShowrooms($reason, $data['showroom_ids'], true);

            if ($names !== null) {
                $changes['showrooms'] = $names[1];
            }

            $this->audit->record($type->auditSubject().'.created', $type->auditSubject(), $reason->id, $reason->name, $type->singular().' создана', $changes);

            return $reason;
        })));
    }

    /**
     * @param  OrderReasonData&array{lock_version: int}  $data
     */
    public function update(OrderReasonType $type, int $id, array $data): OrderTrashReason|OrderDropReason
    {
        return $this->withUniqueField($type->table().'_name_unique', 'name', $type->nameTaken(), fn () => DB::transaction(function () use ($type, $id, $data) {
            $locked = $type->model()::query()->lockForUpdate()->findOrFail($id);
            $locked->ensureLockVersion($data['lock_version']);

            if ($locked->active && ! $data['all_showrooms'] && $data['showroom_ids'] === []) {
                throw ValidationException::withMessages(['showroom_ids' => self::SCOPE_REQUIRED]);
            }

            $locked->fill(collect($data)->only(self::AUDITED)->all());

            $changes = [];

            foreach (array_keys($locked->getDirty()) as $field) {
                $changes[$field] = [$locked->getOriginal($field), $locked->getAttribute($field)];
            }

            $names = $this->syncShowrooms($locked, $data['showroom_ids'], false);

            if ($names !== null) {
                $changes['showrooms'] = $names;
            }

            if ($changes === []) {
                return $locked;
            }

            $locked->bumpLockVersion()->save();

            $this->audit->record($type->auditSubject().'.updated', $type->auditSubject(), $locked->id, $locked->name, $type->singular().' сохранена', $changes);

            return $locked;
        }));
    }

    public function setActive(OrderReasonType $type, int $id, bool $active, mixed $lockVersion): OrderTrashReason|OrderDropReason
    {
        return DB::transaction(function () use ($type, $id, $active, $lockVersion) {
            $locked = $type->model()::query()->lockForUpdate()->findOrFail($id);
            $locked->ensureLockVersion($lockVersion);

            if ($locked->active === $active) {
                return $locked;
            }

            if ($active && ! $locked->hasScope()) {
                throw ValidationException::withMessages([
                    'active' => 'Сначала настройте область применения: все салоны или конкретные салоны.',
                ]);
            }

            $locked->active = $active;
            $locked->bumpLockVersion()->save();

            $this->audit->record(
                $type->auditSubject().($active ? '.activated' : '.deactivated'),
                $type->auditSubject(),
                $locked->id,
                $locked->name,
                $type->singular().($active ? ' включена' : ' отключена'),
                ['active' => [! $active, $active]],
            );

            return $locked;
        });
    }

    /**
     * Newly linked showrooms must exist and be active; showrooms that are already linked
     * stay even if disabled since.
     *
     * @param  list<int>  $ids
     * @return array{0: string, 1: string}|null names before and after, or null when unchanged
     */
    private function syncShowrooms(OrderTrashReason|OrderDropReason $reason, array $ids, bool $created): ?array
    {
        /** @var Collection<int, Showroom> $before */
        $before = $created ? new Collection : $reason->showrooms()->orderBy('name')->get();

        /** @var Collection<int, Showroom> $chosen */
        $chosen = Showroom::query()->sharedLock()->whereIn('id', $ids)->orderBy('name')->get();
        $added = array_diff($ids, $before->modelKeys());

        if ($chosen->count() !== count($ids) || $chosen->whereIn('id', $added)->contains('active', false)) {
            throw ValidationException::withMessages(['showroom_ids' => 'Можно добавить только существующие активные салоны.']);
        }

        $beforeIds = $before->modelKeys();
        $afterIds = $chosen->modelKeys();
        sort($beforeIds);
        sort($afterIds);

        if ($beforeIds === $afterIds) {
            return null;
        }

        $reason->showrooms()->sync($afterIds);

        return [$before->pluck('name')->implode(', '), $chosen->pluck('name')->implode(', ')];
    }
}
