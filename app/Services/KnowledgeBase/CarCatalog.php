<?php

namespace App\Services\KnowledgeBase;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Read-only car catalog of the knowledge base from the separate auto_ru database:
 * marks → models → generations → series (bodies) → modifications with characteristics,
 * and equipment levels with options. Every mark, model and generation is listed whatever its
 * `status`, even without series; models still in production come first, each group from the
 * newest latest generation down.
 */
class CarCatalog
{
    public const CONNECTION = 'auto_ru';

    /** @return Collection<int, stdClass> */
    public function marks(): Collection
    {
        return $this->table('auto_marks as m')
            ->select(['m.id', 'm.name', 'm.name_ru', 'm.url', 'm.logo_min', 'm.logo_dark'])
            ->selectSub(
                fn (Builder $query) => $query->from('auto_models as mo')
                    ->selectRaw('count(*)')
                    ->whereColumn('mo.mark_id', 'm.id'),
                'models_count',
            )
            ->orderByRaw('m.ordering is null')
            ->orderBy('m.ordering')
            ->orderBy('m.name')
            ->get();
    }

    public function mark(string $url): ?stdClass
    {
        return $this->table('auto_marks')->where('url', $url)->first();
    }

    /** @return Collection<int, stdClass> */
    public function models(stdClass $mark): Collection
    {
        return $this->table('auto_models as mo')
            ->select(['mo.id', 'mo.name', 'mo.url', 'mo.year_from', 'mo.year_to'])
            ->selectSub(
                fn (Builder $query) => $query->from('auto_series as s')
                    ->join('auto_generations as g', 'g.id', '=', 's.generation_id')
                    ->select('s.image')
                    ->whereColumn('s.model_id', 'mo.id')
                    ->whereNotNull('s.image')
                    ->orderByRaw('coalesce(g.year_to, 9999) desc')
                    ->orderByDesc('g.year_from')
                    ->orderBy('s.id')
                    ->limit(1),
                'image',
            )
            ->selectSub(
                fn (Builder $query) => $query->from('auto_generations as g')
                    ->selectRaw('max(g.year_from)')
                    ->whereColumn('g.model_id', 'mo.id')
                    ->whereExists(fn (Builder $series) => $series->from('auto_series as s')->whereColumn('s.generation_id', 'g.id')),
                'latest_year',
            )
            ->where('mo.mark_id', $mark->id)
            ->orderByRaw('mo.year_to is not null')
            ->orderByRaw('latest_year is null')
            ->orderByDesc('latest_year')
            ->orderByDesc('mo.year_to')
            ->orderBy('mo.name')
            ->get();
    }

    public function model(stdClass $mark, string $url): ?stdClass
    {
        return $this->table('auto_models')->where('mark_id', $mark->id)->where('url', $url)->first();
    }

    /**
     * Generations from the newest, each with its series; generations without series are skipped.
     *
     * @return Collection<int, stdClass>
     */
    public function generations(stdClass $model): Collection
    {
        $series = $this->table('auto_series')
            ->where('model_id', $model->id)
            ->orderBy('id')
            ->get(['id', 'generation_id', 'external_id', 'name', 'image'])
            ->groupBy('generation_id');

        return $this->table('auto_generations')
            ->where('model_id', $model->id)
            ->orderByRaw('coalesce(year_to, 9999) desc')
            ->orderByDesc('year_from')
            ->orderByDesc('id')
            ->get(['id', 'external_id', 'name', 'year_from', 'year_to'])
            ->map(function (stdClass $generation) use ($series): stdClass {
                $generation->key = $this->generationKey($generation->id, $generation->external_id);
                $generation->series = $series->get($generation->id, collect());

                return $generation;
            });
    }

    /** Generation part of the address: its auto.ru id, or the local id when that is unknown. */
    public function generationKey(int $id, ?int $externalId): string
    {
        return $externalId ? (string) $externalId : 'g'.$id;
    }

    public function series(stdClass $model, string $generationKey, string $seriesExternalId): ?stdClass
    {
        $query = $this->table('auto_series as s')
            ->join('auto_generations as g', 'g.id', '=', 's.generation_id')
            ->select([
                's.id', 's.external_id', 's.name', 's.image', 's.generation_id',
                'g.external_id as generation_external_id', 'g.name as generation_name',
                'g.year_from', 'g.year_to',
            ])
            ->where('s.model_id', $model->id)
            ->where('s.external_id', $seriesExternalId);

        if (str_starts_with($generationKey, 'g')) {
            $query->where('g.id', (int) substr($generationKey, 1))->whereNull('g.external_id');
        } else {
            $query->where('g.external_id', $generationKey);
        }

        return $query->first();
    }

    /** @return Collection<int, stdClass> */
    public function siblingSeries(stdClass $series): Collection
    {
        return $this->table('auto_series')
            ->where('generation_id', $series->generation_id)
            ->orderBy('id')
            ->get(['id', 'external_id', 'name']);
    }

    /** @return Collection<int, stdClass> */
    public function modifications(stdClass $series): Collection
    {
        return $this->table('auto_modifications')
            ->where('series_id', $series->id)
            ->orderBy('engine_volume')
            ->orderBy('engine_power')
            ->orderBy('id')
            ->get();
    }

    public function modification(stdClass $series, string $externalId): ?stdClass
    {
        return $this->table('auto_modifications')
            ->where('series_id', $series->id)
            ->where('external_id', $externalId)
            ->first();
    }

    /**
     * Characteristic sections in catalog order, each with the filled values of the modification.
     *
     * @return Collection<int, array{name: string, items: list<array{name: string, value: string}>}>
     */
    public function characteristics(stdClass $modification): Collection
    {
        $values = $this->table('auto_characteristic_values as v')
            ->join('auto_characteristics as c', 'c.id', '=', 'v.characteristic_id')
            ->where('v.modification_id', $modification->id)
            ->whereNotNull('v.value')
            ->where('v.value', '<>', '')
            ->orderBy('c.sort')
            ->get(['c.name', 'c.parent_id', 'v.value', 'v.unit'])
            ->groupBy('parent_id');

        return $this->sections('auto_characteristics', fn (stdClass $section) => $values
            ->get($section->id, collect())
            ->map(fn (stdClass $value) => [
                'name' => $value->name,
                'value' => trim($value->value.' '.($value->unit ?? '')),
            ])
            ->values()
            ->all());
    }

    /**
     * Named equipment levels of the series (auto.ru keeps one row per level and modification).
     *
     * @return Collection<int, stdClass>
     */
    public function equipments(stdClass $series, ?stdClass $modification = null): Collection
    {
        return $this->table('auto_equipments as e')
            ->join('auto_modifications as m', 'm.id', '=', 'e.modification_id')
            ->select([
                'e.id', 'e.name', 'e.modification_id',
                'm.external_id as modification_external_id', 'm.name as modification_name',
                'm.engine_volume', 'm.engine_power', 'm.engine', 'm.transmission', 'm.drive',
            ])
            ->selectSub(
                fn (Builder $query) => $query->from('auto_option_values as ov')
                    ->selectRaw('count(*)')
                    ->whereColumn('ov.equipment_id', 'e.id'),
                'options_count',
            )
            ->where('e.series_id', $series->id)
            ->where('e.external_id', '>', 0)
            ->when($modification, fn (Builder $query) => $query->where('e.modification_id', $modification->id))
            ->orderBy('m.engine_volume')
            ->orderBy('m.engine_power')
            ->orderBy('e.id')
            ->get();
    }

    public function equipment(stdClass $series, int $id): ?stdClass
    {
        return $this->table('auto_equipments')
            ->where('series_id', $series->id)
            ->where('id', $id)
            ->where('external_id', '>', 0)
            ->first();
    }

    /**
     * Option groups in catalog order with the options of the equipment level.
     *
     * @return Collection<int, array{name: string, items: list<string>}>
     */
    public function options(stdClass $equipment): Collection
    {
        $options = $this->table('auto_option_values as ov')
            ->join('auto_options as o', 'o.id', '=', 'ov.option_id')
            ->where('ov.equipment_id', $equipment->id)
            ->orderBy('o.sort')
            ->get(['o.name', 'o.parent_id'])
            ->groupBy('parent_id');

        return $this->sections('auto_options', fn (stdClass $section) => $options
            ->get($section->id, collect())
            ->pluck('name')
            ->values()
            ->all());
    }

    /**
     * @param  Closure(stdClass): list<mixed>  $items
     * @return Collection<int, array{name: string, items: list<mixed>}>
     */
    private function sections(string $table, Closure $items): Collection
    {
        return $this->table($table)
            ->whereNull('parent_id')
            ->orderBy('sort')
            ->get(['id', 'name'])
            ->map(fn (stdClass $section) => ['name' => $section->name, 'items' => $items($section)])
            ->filter(fn (array $section) => $section['items'] !== [])
            ->values();
    }

    private function table(string $table): Builder
    {
        return DB::connection(self::CONNECTION)->table($table);
    }
}
