<?php

namespace App\Services;

use App\Enums\IconColor;
use App\Models\Agency;
use App\Models\ContactChannel;
use App\Models\SourceClassification;
use App\Models\SourceKind;
use App\Services\Concerns\HandlesUniqueConstraints;
use App\Support\CodeGenerator;
use App\Support\SourceReferenceType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SourceReferenceManager
{
    use HandlesUniqueConstraints;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, icon?: string|null, show_icon?: bool, icon_color?: IconColor, classification_ids?: list<int>, channel_ids?: list<int>}  $data
     */
    public function create(SourceReferenceType $type, array $data, ?string $code = null): SourceKind|SourceClassification|ContactChannel|Agency
    {
        $create = fn () => DB::transaction(function () use ($type, $data, $code) {
            $class = $type->model();
            $attributes = ['name' => $data['name'], 'sort_order' => ReferenceOrder::next($class)];

            if ($type->hasIcon()) {
                $attributes['icon'] = $data['icon'] ?? null;
                $attributes['show_icon'] = $data['show_icon'] ?? false;
                $attributes['icon_color'] = ($data['icon_color'] ?? IconColor::Gray)->value;
            }

            if ($type->hasCode()) {
                $base = $code ?? CodeGenerator::base($data['name'], $type->codeFallback());
                $taken = $class::query()->where('code', 'like', CodeGenerator::prefix($base).'%')->pluck('code');
                $attributes['code'] = CodeGenerator::firstFree($base, $taken);
            }

            $model = $class::create($attributes);

            $changes = array_filter([
                'code' => $attributes['code'] ?? null,
                'name' => $model->name,
                'icon' => $attributes['icon'] ?? null,
                'show_icon' => ($attributes['show_icon'] ?? false) ?: null,
                'icon_color' => isset($attributes['icon']) ? $attributes['icon_color'] : null,
            ], fn ($value) => $value !== null);

            if ($model instanceof SourceKind) {
                $changes += $this->syncKindRules($model, $data, true);
            }

            $this->audit->record(
                $type->auditSubject().'.created',
                $type->auditSubject(),
                $model->id,
                $model->name,
                $type->singular().' '.$type->phrases()['created'],
                $changes,
            );

            return $model;
        });

        $index = $type->table().'_name_unique';

        return $this->withUniqueField($index, 'name', $type->nameTaken(), fn () => $type->hasCode()
            ? $this->withGeneratedCode($type->table().'_code_unique', $create)
            : $create());
    }

    /**
     * @param  array{name: string, lock_version: int, icon?: string|null, show_icon?: bool, icon_color?: IconColor, classification_ids?: list<int>, channel_ids?: list<int>}  $data
     */
    public function update(SourceReferenceType $type, int $id, array $data): SourceKind|SourceClassification|ContactChannel|Agency
    {
        return $this->withUniqueField($type->table().'_name_unique', 'name', $type->nameTaken(), fn () => DB::transaction(function () use ($type, $id, $data) {
            $locked = $type->model()::query()->lockForUpdate()->findOrFail($id);
            $locked->ensureLockVersion($data['lock_version']);

            $locked->fill(['name' => $data['name']]);

            if ($type->hasIcon()) {
                $locked->fill([
                    'icon' => $data['icon'] ?? null,
                    'show_icon' => $data['show_icon'] ?? false,
                    'icon_color' => $data['icon_color'] ?? IconColor::Gray,
                ]);
            }

            $changes = [];

            foreach ($locked->getDirty() as $field => $value) {
                $original = $locked->getOriginal($field);
                $changes[$field] = [$original instanceof IconColor ? $original->value : $original, $value];
            }

            if ($locked instanceof SourceKind) {
                $changes += $this->syncKindRules($locked, $data, false);
            }

            if ($changes === []) {
                return $locked;
            }

            $locked->bumpLockVersion()->save();

            $this->audit->record(
                $type->auditSubject().'.updated',
                $type->auditSubject(),
                $locked->id,
                $locked->name,
                $type->singular().' '.$type->phrases()['updated'],
                $changes,
            );

            return $locked;
        }));
    }

    public function setActive(SourceReferenceType $type, int $id, bool $active, mixed $lockVersion): SourceKind|SourceClassification|ContactChannel|Agency
    {
        return DB::transaction(function () use ($type, $id, $active, $lockVersion) {
            $locked = $type->model()::query()->lockForUpdate()->findOrFail($id);
            $locked->ensureLockVersion($lockVersion);

            if ($locked->active === $active) {
                return $locked;
            }

            $locked->active = $active;
            $locked->bumpLockVersion()->save();

            $this->audit->record(
                $type->auditSubject().($active ? '.activated' : '.deactivated'),
                $type->auditSubject(),
                $locked->id,
                $locked->name,
                $type->singular().' '.$type->phrases()[$active ? 'activated' : 'deactivated'],
                ['active' => [! $active, $active]],
            );

            return $locked;
        });
    }

    /**
     * Applies the allowed classifications and channels of a kind. Newly allowed values
     * must be active; values that are already allowed stay even if disabled since.
     *
     * @param  array{classification_ids?: list<int>, channel_ids?: list<int>}  $data
     * @return array<string, mixed>
     */
    private function syncKindRules(SourceKind $kind, array $data, bool $created): array
    {
        $changes = [];
        $errors = [];

        $relations = [
            'classifications' => ['ids' => $data['classification_ids'] ?? [], 'class' => SourceClassification::class, 'field' => 'classification_ids', 'message' => 'Можно добавить только активные классификации.'],
            'channels' => ['ids' => $data['channel_ids'] ?? [], 'class' => ContactChannel::class, 'field' => 'channel_ids', 'message' => 'Можно добавить только активные каналы обращения.'],
        ];

        $plans = [];

        foreach ($relations as $relation => $config) {
            /** @var Collection<int, SourceClassification|ContactChannel> $before */
            $before = $created ? new Collection : $kind->{$relation}()->orderBy('sort_order')->orderBy('name')->get();
            $added = array_diff($config['ids'], $before->modelKeys());

            /** @var Collection<int, SourceClassification|ContactChannel> $chosen */
            $chosen = $config['class']::query()->sharedLock()->whereIn('id', $config['ids'])->orderBy('sort_order')->orderBy('name')->get();

            if ($chosen->count() !== count($config['ids']) || $chosen->whereIn('id', $added)->contains('active', false)) {
                $errors[$config['field']] = $config['message'];
            }

            $plans[$relation] = [$before, $chosen];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($plans as $relation => [$before, $chosen]) {
            $beforeIds = $before->modelKeys();
            $afterIds = $chosen->modelKeys();
            sort($beforeIds);
            sort($afterIds);

            if ($beforeIds === $afterIds) {
                continue;
            }

            $kind->{$relation}()->sync($afterIds);

            $names = $chosen->pluck('name')->implode(', ');
            $changes[$relation] = $created ? $names : [$before->pluck('name')->implode(', '), $names];
        }

        return $changes;
    }
}
