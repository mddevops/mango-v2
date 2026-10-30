<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\ContactChannel;
use App\Models\IntegrationConnection;
use App\Models\IntegrationConnectionSource;
use App\Models\IntegrationLine;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\SourceClassification;
use App\Models\SourceKind;
use App\Models\SourceSiteIntake;
use App\Services\Concerns\HandlesUniqueConstraints;
use App\Support\CodeGenerator;
use App\Support\IntegrationProvider;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type SourceData array{showroom_id: int, source_kind_id: int, source_classification_id: int|null, agency_id: int|null, name: string, url: string|null, note: string|null, channel_ids: list<int>}
 * @phpstan-type ConnectionData array{site_intake: bool, mango_connection_id: int|null, mango_lines: list<array{type: string, value: string}>}
 */
class SourceManager
{
    use HandlesUniqueConstraints;

    public const LINE_TAKEN = 'Эта линия уже привязана к другому источнику в этом подключении.';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  SourceData  $data
     * @param  ConnectionData|null  $connections
     */
    public function createSource(array $data, ?array $connections): Source
    {
        $base = CodeGenerator::base($data['name'], 'source');

        return $this->withGeneratedCode('sources_code_unique', fn () => DB::transaction(function () use ($data, $connections, $base) {
            $references = $this->references($data, null);

            $source = new Source(collect($data)->except('channel_ids')->all());
            $taken = Source::query()->where('code', 'like', CodeGenerator::prefix($base).'%')->pluck('code');
            $source->code = CodeGenerator::firstFree($base, $taken);
            $source->save();

            $source->channels()->sync($references['channels']->modelKeys());

            $changes = [
                'code' => $source->code,
                'name' => $source->name,
                'showroom' => $references['showroom']->name,
                'kind' => $references['kind']->name,
                'classification' => $references['classification']?->name,
                'agency' => $references['agency']?->name,
                'url' => $source->url,
                'channels' => $references['channels']->pluck('name')->implode(', '),
                'note' => $source->note,
            ];

            if ($connections !== null) {
                $changes += $this->applyConnections($source, $connections);
            }

            $this->audit->record('source.created', 'source', $source->id, $source->name, 'Создан источник', $changes, [$source->showroom_id]);

            return $source;
        }));
    }

    /**
     * @param  array{showroom_id: int, source_kind_id: int, source_classification_id: int|null, agency_id: int|null, name: string, url: string|null, note: string|null, channel_ids: list<int>, lock_version: int}  $data
     * @param  ConnectionData|null  $connections
     */
    public function updateSource(Source $source, array $data, ?array $connections): Source
    {
        return DB::transaction(function () use ($source, $data, $connections) {
            /** @var Source $locked */
            $locked = Source::query()->lockForUpdate()->findOrFail($source->id);
            $locked->ensureLockVersion($data['lock_version']);

            if ($locked->transfer_from_showroom_id !== null && $data['showroom_id'] !== $locked->showroom_id) {
                throw ValidationException::withMessages(['showroom_id' => 'Источник принимает заявки, переданные из другого салона, и закреплён за своим салоном.']);
            }

            $locked->load(['showroom', 'kind', 'classification', 'agency', 'channels']);
            $previous = [
                'showroom' => $locked->showroom,
                'kind' => $locked->kind,
                'classification' => $locked->classification,
                'agency' => $locked->agency,
            ];
            $previousChannels = $locked->channels->sortBy('sort_order')->pluck('name')->implode(', ');
            $current = $this->references($data, $locked);

            $locked->fill(collect($data)->except(['channel_ids', 'lock_version'])->all());

            $changes = [];

            foreach (['name', 'url', 'note'] as $field) {
                if ($locked->isDirty($field)) {
                    $changes[$field] = [$locked->getOriginal($field), $locked->getAttribute($field)];
                }
            }

            $fields = ['showroom' => 'showroom_id', 'kind' => 'source_kind_id', 'classification' => 'source_classification_id', 'agency' => 'agency_id'];

            foreach ($fields as $key => $field) {
                if ($locked->isDirty($field)) {
                    $changes[$key] = [$previous[$key]?->name, $current[$key]?->name];
                }
            }

            $channelIds = $current['channels']->modelKeys();
            $currentIds = $locked->channels->modelKeys();
            sort($channelIds);
            sort($currentIds);

            if ($channelIds !== $currentIds) {
                $locked->channels()->sync($channelIds);
                $changes['channels'] = [$previousChannels, $current['channels']->pluck('name')->implode(', ')];
            }

            if ($connections !== null) {
                $changes += $this->applyConnections($locked, $connections);
            } elseif ($locked->isDirty('showroom_id')) {
                $this->ensureBindingsAllowShowroom($locked);
            }

            if ($changes === []) {
                return $locked;
            }

            $showroomIds = [$locked->getOriginal('showroom_id'), $locked->showroom_id];

            $locked->bumpLockVersion()->save();

            $this->audit->record('source.updated', 'source', $locked->id, $locked->name, 'Изменён источник', $changes, $showroomIds);

            return $locked;
        });
    }

    public function setSourceActive(Source $source, bool $active, mixed $lockVersion): Source
    {
        return DB::transaction(function () use ($source, $active, $lockVersion) {
            /** @var Source $locked */
            $locked = Source::query()->lockForUpdate()->findOrFail($source->id);
            $locked->ensureLockVersion($lockVersion);

            if ($locked->active === $active) {
                return $locked;
            }

            $locked->active = $active;
            $locked->bumpLockVersion()->save();

            $this->audit->record(
                $active ? 'source.activated' : 'source.deactivated',
                'source',
                $locked->id,
                $locked->name,
                $active ? 'Источник включён' : 'Источник отключён',
                ['active' => [! $active, $active]],
                [$locked->showroom_id],
            );

            return $locked;
        });
    }

    /**
     * Loads related records under a shared lock so they cannot change while the source
     * is saved. A newly chosen value must be active and allowed for the kind; a value the
     * source already uses with the same kind may stay after it was disabled or the rules
     * changed, so existing data never blocks saving unrelated fields.
     *
     * @param  SourceData  $data
     * @return array{showroom: Showroom, kind: SourceKind, classification: SourceClassification|null, agency: Agency|null, channels: Collection<int, ContactChannel>}
     */
    private function references(array $data, ?Source $source): array
    {
        $showroom = Showroom::query()->sharedLock()->find($data['showroom_id']);
        $kind = SourceKind::query()->sharedLock()->find($data['source_kind_id']);
        $classification = $data['source_classification_id'] === null ? null : SourceClassification::query()->sharedLock()->find($data['source_classification_id']);
        $agency = $data['agency_id'] === null ? null : Agency::query()->sharedLock()->find($data['agency_id']);
        /** @var Collection<int, ContactChannel> $channels */
        $channels = ContactChannel::query()->sharedLock()->whereIn('id', $data['channel_ids'])->orderBy('sort_order')->orderBy('name')->get();

        $errors = [];

        if ($showroom === null || (! $showroom->active && $showroom->id !== $source?->showroom_id)) {
            $errors['showroom_id'] = 'Выбранный салон отключён или не существует. Выберите активный салон.';
        }

        if ($kind === null || (! $kind->active && $kind->id !== $source?->source_kind_id)) {
            $errors['source_kind_id'] = 'Выбранный вид источника отключён или не существует. Выберите активный вид.';
        }

        if ($data['agency_id'] !== null && ($agency === null || (! $agency->active && $agency->id !== $source?->agency_id))) {
            $errors['agency_id'] = 'Выбранное агентство отключено или не существует. Выберите активное агентство.';
        }

        if ($errors !== [] || $showroom === null || $kind === null) {
            throw ValidationException::withMessages($errors);
        }

        $sameKind = $source !== null && $source->source_kind_id === $kind->id;

        if ($data['source_classification_id'] !== null) {
            $unchanged = $sameKind && $source->source_classification_id === $data['source_classification_id'];

            if ($classification === null) {
                $errors['source_classification_id'] = 'Выбранная классификация не существует.';
            } elseif (! $unchanged && ! $classification->active) {
                $errors['source_classification_id'] = 'Выбранная классификация отключена. Выберите активную классификацию.';
            } elseif (! $unchanged && ! $kind->classifications()->whereKey($classification->id)->exists()) {
                $errors['source_classification_id'] = "Классификация «{$classification->name}» не разрешена для вида «{$kind->name}».";
            }
        }

        if ($channels->count() !== count($data['channel_ids'])) {
            $errors['channel_ids'] = 'Выбранный канал обращения не существует.';
        } else {
            $kept = $sameKind ? $source->channels()->pluck('contact_channels.id')->all() : [];
            $allowed = $kind->channels()->pluck('contact_channels.id')->all();

            foreach ($channels as $channel) {
                if (in_array($channel->id, $kept, true)) {
                    continue;
                }

                if (! $channel->active) {
                    $errors['channel_ids'] = "Канал «{$channel->name}» отключён.";
                } elseif (! in_array($channel->id, $allowed, true)) {
                    $errors['channel_ids'] = "Канал «{$channel->name}» не разрешён для вида «{$kind->name}».";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['showroom' => $showroom, 'kind' => $kind, 'classification' => $classification, 'agency' => $agency, 'channels' => $channels];
    }

    /**
     * @param  ConnectionData  $connections
     * @return array<string, mixed>
     */
    private function applyConnections(Source $source, array $connections): array
    {
        return [
            ...$this->applySiteIntake($source, $connections['site_intake']),
            ...$this->applyMango($source, $connections['mango_connection_id'], $connections['mango_lines']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function applySiteIntake(Source $source, bool $enabled): array
    {
        /** @var SourceSiteIntake|null $intake */
        $intake = $source->siteIntake()->lockForUpdate()->first();
        $before = $intake !== null && $intake->active;

        if ($before === $enabled) {
            return [];
        }

        if ($intake === null) {
            $intake = new SourceSiteIntake(['source_id' => $source->id]);
        }

        $intake->active = $enabled;
        $intake->save();

        return ['site_intake' => [$before ? 'включён' : 'не настроен', $enabled ? 'включён' : 'отключён']];
    }

    /**
     * @param  list<array{type: string, value: string}>  $lines
     * @return array<string, mixed>
     */
    private function applyMango(Source $source, ?int $connectionId, array $lines): array
    {
        $provider = IntegrationProvider::MangoVpbx;

        /** @var IntegrationConnectionSource|null $binding */
        $binding = $source->connectionBindings()->where('provider', $provider->value)->with(['connection', 'lines'])->lockForUpdate()->first();
        $beforeName = $binding?->connection->name;
        $beforeLines = $binding ? $this->describeLines(array_values($binding->lines->map(fn (IntegrationLine $line) => ['type' => $line->type, 'value' => $line->value])->all())) : '';

        if ($connectionId === null) {
            if ($binding === null) {
                return [];
            }

            $binding->delete();

            return ['mango_connection' => [$beforeName, null], 'mango_lines' => [$beforeLines, '']];
        }

        /** @var IntegrationConnection|null $connection */
        $connection = IntegrationConnection::query()
            ->where('provider', $provider->value)
            ->sharedLock()
            ->with('showrooms:id')
            ->find($connectionId);
        $unchanged = $binding !== null && $binding->integration_connection_id === $connectionId;

        if ($connection === null) {
            throw ValidationException::withMessages(['connections.mango_connection_id' => 'Выбранное подключение Mango VPBX не существует.']);
        }

        if (! $unchanged && ! $connection->active) {
            throw ValidationException::withMessages(['connections.mango_connection_id' => 'Выбранное подключение Mango VPBX отключено.']);
        }

        if (! $connection->showrooms->contains('id', $source->showroom_id)) {
            throw ValidationException::withMessages(['connections.mango_connection_id' => "Подключение «{$connection->name}» не разрешено для салона источника."]);
        }

        if (! $unchanged) {
            $binding?->delete();
            $binding = IntegrationConnectionSource::create([
                'integration_connection_id' => $connection->id,
                'provider' => $provider,
                'source_id' => $source->id,
            ]);
        } else {
            /** @var IntegrationConnectionSource $binding */
            $binding->lines()->delete();
        }

        foreach ($lines as $index => $line) {
            $this->withUniqueField('integration_lines_connection_line_unique', "connections.mango_lines.{$index}.value", self::LINE_TAKEN, fn () => IntegrationLine::create([
                'integration_connection_source_id' => $binding->id,
                'integration_connection_id' => $connection->id,
                'type' => $line['type'],
                'value' => $line['value'],
                'match_key' => IntegrationLine::matchKey($line['type'], $line['value']),
            ]));
        }

        $changes = [];
        $afterLines = $this->describeLines($lines);

        if ($beforeName !== $connection->name || ! $unchanged) {
            $changes['mango_connection'] = [$beforeName, $connection->name];
        }

        if ($beforeLines !== $afterLines) {
            $changes['mango_lines'] = [$beforeLines, $afterLines];
        }

        return $changes;
    }

    private function ensureBindingsAllowShowroom(Source $source): void
    {
        $blocked = $source->connectionBindings()
            ->whereDoesntHave('connection.showrooms', fn ($query) => $query->whereKey($source->showroom_id))
            ->with('connection:id,name')
            ->first();

        if ($blocked !== null) {
            throw ValidationException::withMessages([
                'showroom_id' => "Источник привязан к подключению «{$blocked->connection->name}», которое не разрешено для выбранного салона.",
            ]);
        }
    }

    /**
     * @param  list<array{type: string, value: string}>  $lines
     */
    private function describeLines(array $lines): string
    {
        return collect($lines)
            ->map(fn (array $line) => ($line['type'] === IntegrationLine::SIP ? 'SIP ' : '').$line['value'])
            ->implode(', ');
    }
}
