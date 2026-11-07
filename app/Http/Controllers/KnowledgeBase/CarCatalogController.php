<?php

namespace App\Http\Controllers\KnowledgeBase;

use App\Http\Controllers\Controller;
use App\Services\KnowledgeBase\CarCatalog;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * The «Каталог автомобилей» of the knowledge base, page by page as in auto.ru/catalog/cars:
 * marks, models of a mark, generations of a model, modifications of a series, then
 * the characteristics of a modification and the options of an equipment level.
 */
class CarCatalogController extends Controller
{
    public const IMAGE_DISK = 'auto_catalog';

    public function __construct(private readonly CarCatalog $catalog) {}

    public function index(): Response
    {
        return Inertia::render('knowledge-base/index');
    }

    public function marks(): Response
    {
        return Inertia::render('knowledge-base/cars/marks', [
            'marks' => $this->catalog->marks()->map(fn (stdClass $mark) => [
                'name' => $mark->name,
                'name_ru' => $mark->name_ru,
                'href' => route('knowledge-base.mark', $mark->url, false),
                'logo' => $this->imageUrl($mark->logo_min),
                'logo_dark' => $this->imageUrl($mark->logo_dark),
                'models_count' => (int) $mark->models_count,
            ]),
        ]);
    }

    public function mark(string $mark): Response
    {
        $mark = $this->findMark($mark);

        return Inertia::render('knowledge-base/cars/mark', [
            'mark' => [
                ...$this->markRef($mark),
                'name_ru' => $mark->name_ru,
                'country' => $mark->country,
                'logo' => $this->imageUrl($mark->logo_min),
                'logo_dark' => $this->imageUrl($mark->logo_dark),
            ],
            'models' => $this->catalog->models($mark)->map(fn (stdClass $model) => [
                'name' => $model->name,
                'href' => route('knowledge-base.model', [$mark->url, $model->url], false),
                'image' => $this->imageUrl($model->image),
                'year_from' => $model->year_from,
                'year_to' => $model->year_to,
            ]),
        ]);
    }

    public function model(string $mark, string $model): Response
    {
        $mark = $this->findMark($mark);
        $model = $this->findModel($mark, $model);

        return Inertia::render('knowledge-base/cars/model', [
            'mark' => $this->markRef($mark),
            'model' => [
                ...$this->modelRef($mark, $model),
                'year_from' => $model->year_from,
                'year_to' => $model->year_to,
                'class' => $model->class,
            ],
            'generations' => $this->catalog->generations($model)->map(fn (stdClass $generation) => [
                'key' => $generation->key,
                'name' => $generation->name,
                'year_from' => $generation->year_from,
                'year_to' => $generation->year_to,
                'series' => $generation->series->map(fn (stdClass $series) => [
                    'name' => $series->name,
                    'href' => route('knowledge-base.series', [$mark->url, $model->url, $generation->key, $series->external_id], false),
                    'image' => $this->imageUrl($series->image),
                ])->values(),
            ]),
        ]);
    }

    public function series(string $mark, string $model, string $generation, string $series): Response
    {
        [$mark, $model, $series] = $this->findSeries($mark, $model, $generation, $series);

        return Inertia::render('knowledge-base/cars/series', [
            ...$this->seriesContext($mark, $model, $series),
            'modifications' => $this->catalog->modifications($series)->map(fn (stdClass $modification) => [
                'href' => $this->specificationsHref($mark, $model, $series, $modification->external_id),
                'engine' => $this->engineLabel($modification),
                'transmission' => $modification->transmission,
                'drive' => $modification->drive,
                'consumption' => $this->consumption($modification),
                'acceleration' => $modification->acceleration_0_100 !== null ? $modification->acceleration_0_100.' с' : null,
            ]),
            'equipments' => $this->catalog->equipments($series)->map(fn (stdClass $equipment) => [
                'name' => $equipment->name,
                'href' => $this->equipmentHref($mark, $model, $series, $equipment->id),
                'modification' => $this->modificationLabel($equipment),
                'options_count' => (int) $equipment->options_count,
            ]),
        ]);
    }

    public function specifications(string $mark, string $model, string $generation, string $series, string $modification): Response
    {
        [$mark, $model, $series] = $this->findSeries($mark, $model, $generation, $series);
        $modification = $this->catalog->modification($series, $modification) ?? abort(404);
        $sections = $this->catalog->characteristics($modification);
        $value = fn (string $name): ?string => $sections->pluck('items')->flatten(1)->firstWhere('name', $name)['value'] ?? null;

        return Inertia::render('knowledge-base/cars/specifications', [
            ...$this->seriesContext($mark, $model, $series),
            'modification' => [
                'name' => $this->modificationLabel($modification),
                'href' => $this->specificationsHref($mark, $model, $series, $modification->external_id),
            ],
            'modifications' => $this->modificationOptions($mark, $model, $series),
            'summary' => $this->filled([
                'Объем' => $modification->engine_volume !== null ? $modification->engine_volume.' л' : null,
                'Мощность' => $modification->engine_power !== null ? $modification->engine_power.' л.с.' : null,
                'Коробка' => $modification->transmission,
                'Тип двигателя' => $value('Тип двигателя'),
                'Топливо' => $value('Марка топлива'),
                'Привод' => $modification->drive,
                'Разгон' => $modification->acceleration_0_100 !== null ? $modification->acceleration_0_100.' с' : null,
                'Расход' => $modification->consumption_100_km !== null ? $modification->consumption_100_km.' л' : null,
            ]),
            'dimensions' => $this->filled([
                'Длина' => $value('Длина'),
                'Ширина' => $value('Ширина'),
                'Высота' => $value('Высота'),
                'Клиренс' => $value('Клиренс'),
            ]),
            'sections' => $sections,
            'equipments' => $this->catalog->equipments($series, $modification)->map(fn (stdClass $equipment) => [
                'name' => $equipment->name,
                'href' => $this->equipmentHref($mark, $model, $series, $equipment->id),
            ]),
        ]);
    }

    public function equipment(string $mark, string $model, string $generation, string $series, int $equipment): Response
    {
        [$mark, $model, $series] = $this->findSeries($mark, $model, $generation, $series);
        $equipment = $this->catalog->equipment($series, $equipment) ?? abort(404);
        $modification = $this->catalog->modifications($series)->firstWhere('id', $equipment->modification_id) ?? abort(404);

        return Inertia::render('knowledge-base/cars/equipment', [
            ...$this->seriesContext($mark, $model, $series),
            'modification' => [
                'name' => $this->modificationLabel($modification),
                'href' => $this->specificationsHref($mark, $model, $series, $modification->external_id),
            ],
            'modifications' => $this->modificationOptions($mark, $model, $series),
            'equipment' => ['name' => $equipment->name],
            'equipments' => $this->catalog->equipments($series, $modification)->map(fn (stdClass $item) => [
                'name' => $item->name,
                'href' => $this->equipmentHref($mark, $model, $series, $item->id),
                'details' => $this->modificationLabel($item),
                'active' => (int) $item->id === (int) $equipment->id,
            ]),
            'sections' => $this->catalog->options($equipment),
        ]);
    }

    private function findMark(string $url): stdClass
    {
        return $this->catalog->mark($url) ?? abort(404);
    }

    private function findModel(stdClass $mark, string $url): stdClass
    {
        return $this->catalog->model($mark, $url) ?? abort(404);
    }

    /** @return array{stdClass, stdClass, stdClass} */
    private function findSeries(string $mark, string $model, string $generation, string $series): array
    {
        $mark = $this->findMark($mark);
        $model = $this->findModel($mark, $model);

        return [$mark, $model, $this->catalog->series($model, $generation, $series) ?? abort(404)];
    }

    /** @return array<string, mixed> */
    private function seriesContext(stdClass $mark, stdClass $model, stdClass $series): array
    {
        $generationKey = $this->catalog->generationKey($series->generation_id, $series->generation_external_id);

        return [
            'mark' => $this->markRef($mark),
            'model' => $this->modelRef($mark, $model),
            'series' => [
                'name' => $series->name,
                'href' => route('knowledge-base.series', [$mark->url, $model->url, $generationKey, $series->external_id], false),
                'image' => $this->imageUrl($series->image),
                'generation' => [
                    'name' => $series->generation_name,
                    'year_from' => $series->year_from,
                    'year_to' => $series->year_to,
                ],
            ],
            'siblings' => $this->catalog->siblingSeries($series)->map(fn (stdClass $sibling) => [
                'name' => $sibling->name,
                'href' => route('knowledge-base.series', [$mark->url, $model->url, $generationKey, $sibling->external_id], false),
                'active' => (int) $sibling->id === (int) $series->id,
            ]),
        ];
    }

    /** @return list<array{name: string, href: string}> */
    private function modificationOptions(stdClass $mark, stdClass $model, stdClass $series): array
    {
        return $this->catalog->modifications($series)->map(fn (stdClass $modification) => [
            'name' => $this->modificationLabel($modification),
            'href' => $this->specificationsHref($mark, $model, $series, $modification->external_id),
        ])->values()->all();
    }

    /** @return array{name: string, href: string} */
    private function markRef(stdClass $mark): array
    {
        return ['name' => $mark->name, 'href' => route('knowledge-base.mark', $mark->url, false)];
    }

    /** @return array{name: string, href: string} */
    private function modelRef(stdClass $mark, stdClass $model): array
    {
        return ['name' => $model->name, 'href' => route('knowledge-base.model', [$mark->url, $model->url], false)];
    }

    private function specificationsHref(stdClass $mark, stdClass $model, stdClass $series, int|string $modification): string
    {
        $generation = $this->catalog->generationKey($series->generation_id, $series->generation_external_id);

        return route('knowledge-base.specifications', [$mark->url, $model->url, $generation, $series->external_id, $modification], false);
    }

    private function equipmentHref(stdClass $mark, stdClass $model, stdClass $series, int|string $equipment): string
    {
        $generation = $this->catalog->generationKey($series->generation_id, $series->generation_external_id);

        return route('knowledge-base.equipment', [$mark->url, $model->url, $generation, $series->external_id, $equipment], false);
    }

    /** «1.5 / 203 л.с. / бензин», as in the auto.ru modification table. */
    private function engineLabel(stdClass $modification): string
    {
        return collect([
            $modification->engine_volume,
            $modification->engine_power !== null ? $modification->engine_power.' л.с.' : null,
            $modification->engine,
        ])->filter(fn ($part) => $part !== null && $part !== '')->implode(' / ');
    }

    /** «1.5 CVT (203 л.с.)», or the engine, gearbox and drive when the catalog has no name. */
    private function modificationLabel(stdClass $modification): string
    {
        $name = $modification->modification_name ?? $modification->name;
        $details = collect([$this->engineLabel($modification), $modification->transmission, $modification->drive])
            ->filter()
            ->implode(', ');

        return preg_match('/\d/', (string) $name) === 1 ? $name : ($details !== '' ? $details : (string) $name);
    }

    private function consumption(stdClass $modification): ?string
    {
        if ($modification->consumption_city !== null && $modification->consumption_highway !== null) {
            return $modification->consumption_city.' / '.$modification->consumption_highway;
        }

        return $modification->consumption_100_km ?? $modification->consumption_city;
    }

    /**
     * @param  array<string, string|null>  $values
     * @return list<array{label: string, value: string}>
     */
    private function filled(array $values): array
    {
        return collect($values)
            ->filter(fn (?string $value) => $value !== null && $value !== '')
            ->map(fn (string $value, string $label) => ['label' => $label, 'value' => $value])
            ->values()
            ->all();
    }

    private function imageUrl(?string $path): ?string
    {
        return $path ? Storage::disk(self::IMAGE_DISK)->url($path) : null;
    }
}
