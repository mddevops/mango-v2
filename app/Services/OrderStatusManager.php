<?php

namespace App\Services;

use App\Http\Requests\Orders\OrderStatusRequest;
use App\Models\OrderStatus;
use App\Services\Concerns\HandlesUniqueConstraints;
use App\Support\CodeGenerator;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-import-type OrderStatusData from OrderStatusRequest
 */
class OrderStatusManager
{
    use HandlesUniqueConstraints;

    public const INITIAL_REQUIREMENTS = 'Начальный статус получают все новые заявки автоматически, поэтому он не может требовать дату или причину.';

    private const AUDITED = [
        'name', 'description', 'color', 'show_as_tab',
        'requires_callback_at', 'requires_arrival_at', 'required_reason',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  OrderStatusData  $data
     */
    public function create(array $data, ?string $code = null): OrderStatus
    {
        return $this->withUniqueField('order_statuses_name_unique', 'name', OrderStatus::NAME_TAKEN, fn () => $this->withGeneratedCode('order_statuses_code_unique', fn () => DB::transaction(function () use ($data, $code) {
            $base = $code ?? CodeGenerator::base($data['name'], 'status');
            $taken = OrderStatus::query()->where('code', 'like', CodeGenerator::prefix($base).'%')->pluck('code');

            $status = OrderStatus::create([
                ...$data,
                'code' => CodeGenerator::firstFree($base, $taken),
                'sort_order' => ReferenceOrder::next(OrderStatus::class),
            ]);

            $changes = ['code' => $status->code];

            foreach (self::AUDITED as $field) {
                $changes[$field] = $this->plain($status->getAttribute($field));
            }

            $this->audit->record('order_status.created', 'order_status', $status->id, $status->name, 'Статус заявки создан', $changes);

            return $status;
        })));
    }

    /**
     * @param  OrderStatusData&array{lock_version: int}  $data
     */
    public function update(int $id, array $data): OrderStatus
    {
        return $this->withUniqueField('order_statuses_name_unique', 'name', OrderStatus::NAME_TAKEN, fn () => DB::transaction(function () use ($id, $data) {
            $locked = OrderStatus::query()->lockForUpdate()->findOrFail($id);
            $locked->ensureLockVersion($data['lock_version']);

            $locked->fill(collect($data)->only(self::AUDITED)->all());

            if ($locked->is_initial && $locked->hasRequirements()) {
                throw ValidationException::withMessages(['requirements' => self::INITIAL_REQUIREMENTS]);
            }

            $changes = [];

            foreach (array_keys($locked->getDirty()) as $field) {
                $changes[$field] = [$this->plain($locked->getOriginal($field)), $this->plain($locked->getAttribute($field))];
            }

            if ($changes === []) {
                return $locked;
            }

            $locked->bumpLockVersion()->save();

            $this->audit->record('order_status.updated', 'order_status', $locked->id, $locked->name, 'Статус заявки сохранён', $changes);

            return $locked;
        }));
    }

    public function setActive(int $id, bool $active, mixed $lockVersion): OrderStatus
    {
        return DB::transaction(function () use ($id, $active, $lockVersion) {
            $locked = OrderStatus::query()->lockForUpdate()->findOrFail($id);
            $locked->ensureLockVersion($lockVersion);

            if ($locked->active === $active) {
                return $locked;
            }

            if (! $active && $locked->is_initial) {
                throw ValidationException::withMessages([
                    'active' => 'Это начальный статус новых заявок. Сначала выберите другой начальный статус.',
                ]);
            }

            if (! $active && $locked->purpose !== null) {
                throw ValidationException::withMessages([
                    'active' => "Статус используется системой ({$locked->purpose->label()}) и не может быть отключён.",
                ]);
            }

            $locked->active = $active;
            $locked->bumpLockVersion()->save();

            $this->audit->record(
                $active ? 'order_status.activated' : 'order_status.deactivated',
                'order_status',
                $locked->id,
                $locked->name,
                $active ? 'Статус заявки включён' : 'Статус заявки отключён',
                ['active' => [! $active, $active]],
            );

            return $locked;
        });
    }

    /**
     * Moves the initial flag in one transaction; the unique index on `initial_marker`
     * rejects a concurrent second initial status.
     */
    public function makeInitial(int $id, mixed $lockVersion): OrderStatus
    {
        $message = 'Начальный статус уже изменил другой пользователь. Обновите страницу и повторите изменение.';

        return $this->withUniqueField('order_statuses_initial_marker_unique', 'lock_version', $message, fn () => DB::transaction(function () use ($id, $lockVersion) {
            $locked = OrderStatus::query()->lockForUpdate()->findOrFail($id);
            $locked->ensureLockVersion($lockVersion);

            if ($locked->is_initial) {
                return $locked;
            }

            if (! $locked->active) {
                throw ValidationException::withMessages(['is_initial' => 'Начальным может быть только активный статус.']);
            }

            if ($locked->hasRequirements()) {
                throw ValidationException::withMessages(['is_initial' => self::INITIAL_REQUIREMENTS]);
            }

            if ($locked->purpose !== null) {
                throw ValidationException::withMessages(['is_initial' => 'Системный статус не может быть начальным.']);
            }

            $previous = OrderStatus::query()->where('is_initial', true)->lockForUpdate()->first();

            if ($previous !== null) {
                $previous->is_initial = false;
                $previous->bumpLockVersion()->save();
            }

            $locked->is_initial = true;
            $locked->bumpLockVersion()->save();

            $this->audit->record(
                'order_status.initial_changed',
                'order_status',
                $locked->id,
                $locked->name,
                'Изменён начальный статус новых заявок',
                ['initial_status' => [$previous?->name, $locked->name]],
            );

            return $locked;
        }));
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
