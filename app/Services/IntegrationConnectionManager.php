<?php

namespace App\Services;

use App\Models\IntegrationConnection;
use App\Models\Showroom;
use App\Models\Source;
use App\Services\Concerns\HandlesUniqueConstraints;
use App\Support\IntegrationProvider;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves connection settings. Nothing here contacts the provider: a saved connection
 * stays "unverified" until a working adapter checks it.
 *
 * Secret values never leave this class: the audit trail only records that a secret was
 * set, replaced or removed.
 *
 * @phpstan-type ConnectionData array{name: string, showroom_ids: list<int>, secrets: array<string, string>, clear: list<string>}
 */
class IntegrationConnectionManager
{
    use HandlesUniqueConstraints;

    public const NAME_TAKEN = 'Подключение с таким названием уже существует.';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  ConnectionData  $data
     */
    public function create(IntegrationProvider $provider, array $data): IntegrationConnection
    {
        return $this->withUniqueField('integration_connections_provider_name_unique', 'name', self::NAME_TAKEN, fn () => DB::transaction(function () use ($provider, $data) {
            $showrooms = $this->showrooms($data['showroom_ids'], new Collection);

            $connection = new IntegrationConnection(['provider' => $provider, 'name' => $data['name']]);
            $credentials = $this->applySecrets($connection, $data);
            $connection->save();
            $connection->showrooms()->sync($showrooms->modelKeys());

            $this->audit->record('integration_connection.created', 'integration_connection', $connection->id, $connection->name, 'Создано подключение '.$provider->label(), array_filter([
                'name' => $connection->name,
                'showrooms' => $showrooms->pluck('name')->implode(', '),
                'credentials' => $credentials,
            ]), $showrooms->modelKeys());

            return $connection;
        }));
    }

    /**
     * @param  array{name: string, showroom_ids: list<int>, secrets: array<string, string>, clear: list<string>, lock_version: int}  $data
     */
    public function update(IntegrationConnection $connection, array $data): IntegrationConnection
    {
        return $this->withUniqueField('integration_connections_provider_name_unique', 'name', self::NAME_TAKEN, fn () => DB::transaction(function () use ($connection, $data) {
            /** @var IntegrationConnection $locked */
            $locked = IntegrationConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $locked->ensureLockVersion($data['lock_version']);

            /** @var Collection<int, Showroom> $before */
            $before = $locked->showrooms()->orderBy('name')->get();
            $showrooms = $this->showrooms($data['showroom_ids'], $before);
            $this->ensureBoundSourcesStayAllowed($locked, $showrooms->modelKeys());

            $changes = [];

            $locked->name = $data['name'];

            if ($locked->isDirty('name')) {
                $changes['name'] = [$locked->getOriginal('name'), $locked->name];
            }

            $beforeIds = $before->modelKeys();
            $afterIds = $showrooms->modelKeys();
            sort($beforeIds);
            sort($afterIds);

            if ($beforeIds !== $afterIds) {
                $locked->showrooms()->sync($afterIds);
                $changes['showrooms'] = [$before->pluck('name')->implode(', '), $showrooms->pluck('name')->implode(', ')];
            }

            $credentials = $this->applySecrets($locked, $data);

            if ($credentials !== null) {
                $changes['credentials'] = $credentials;
            }

            if ($changes === []) {
                return $locked;
            }

            $locked->bumpLockVersion()->save();

            $this->audit->record('integration_connection.updated', 'integration_connection', $locked->id, $locked->name, 'Изменено подключение '.$locked->provider->label(), $changes, [...$beforeIds, ...$afterIds]);

            return $locked;
        }));
    }

    public function setActive(IntegrationConnection $connection, bool $active, mixed $lockVersion): IntegrationConnection
    {
        return DB::transaction(function () use ($connection, $active, $lockVersion) {
            /** @var IntegrationConnection $locked */
            $locked = IntegrationConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $locked->ensureLockVersion($lockVersion);

            if ($locked->active === $active) {
                return $locked;
            }

            $locked->active = $active;
            $locked->bumpLockVersion()->save();

            $this->audit->record(
                $active ? 'integration_connection.activated' : 'integration_connection.deactivated',
                'integration_connection',
                $locked->id,
                $locked->name,
                $active ? 'Подключение включено' : 'Подключение отключено',
                ['active' => [! $active, $active]],
                $locked->showrooms()->pluck('showrooms.id')->all(),
            );

            return $locked;
        });
    }

    /**
     * A non-empty value sets or replaces a secret, an explicit clear removes it and an
     * empty field keeps it. Returns a description without any secret value.
     *
     * @param  array{secrets: array<string, string>, clear: list<string>}  $data
     */
    private function applySecrets(IntegrationConnection $connection, array $data): ?string
    {
        $secrets = $connection->secrets ?? [];
        $notes = [];

        foreach ($connection->provider->secretFields() as $key => $label) {
            $value = $data['secrets'][$key] ?? '';
            $had = ($secrets[$key] ?? '') !== '';

            if ($value !== '') {
                if (($secrets[$key] ?? null) !== $value) {
                    $notes[] = $label.': '.($had ? 'заменён' : 'задан');
                }

                $secrets[$key] = $value;
            } elseif (in_array($key, $data['clear'], true) && $had) {
                unset($secrets[$key]);
                $notes[] = $label.': удалён';
            }
        }

        if ($notes === []) {
            return null;
        }

        $connection->secrets = $secrets === [] ? null : $secrets;

        return implode('; ', $notes);
    }

    /**
     * Newly allowed showrooms must be active; already allowed ones may stay.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, Showroom>  $current
     * @return Collection<int, Showroom>
     */
    private function showrooms(array $ids, Collection $current): Collection
    {
        /** @var Collection<int, Showroom> $showrooms */
        $showrooms = Showroom::query()->sharedLock()->whereIn('id', $ids)->orderBy('name')->get();

        $invalid = $showrooms->count() !== count($ids)
            || $showrooms->contains(fn (Showroom $showroom) => ! $showroom->active && ! $current->contains('id', $showroom->id));

        if ($invalid) {
            throw ValidationException::withMessages(['showroom_ids' => 'Выбранный салон отключён или не существует. Выберите активные салоны.']);
        }

        return $showrooms;
    }

    /**
     * @param  array<int, int|string>  $showroomIds
     */
    private function ensureBoundSourcesStayAllowed(IntegrationConnection $connection, array $showroomIds): void
    {
        $blocked = Source::query()
            ->whereHas('connectionBindings', fn ($query) => $query->where('integration_connection_id', $connection->id))
            ->whereNotIn('showroom_id', $showroomIds)
            ->with('showroom:id,name')
            ->orderBy('name')
            ->first();

        if ($blocked !== null) {
            throw ValidationException::withMessages([
                'showroom_ids' => "К подключению привязан источник «{$blocked->name}» салона «{$blocked->showroom->name}». Сначала отвяжите его.",
            ]);
        }
    }
}
