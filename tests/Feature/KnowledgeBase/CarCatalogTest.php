<?php

namespace Tests\Feature\KnowledgeBase;

use App\Services\KnowledgeBase\CarCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\TestCase;

class CarCatalogTest extends TestCase
{
    use BuildsOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['database.connections.'.CarCatalog::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge(CarCatalog::CONNECTION);
        $this->createCatalog();
    }

    public function test_catalog_goes_from_marks_to_equipment_options(): void
    {
        $user = $this->employee('operator');
        $base = '/knowledge-base/cars/acura/integra/23335197/23335247';

        $this->actingAs($user)->get('/knowledge-base')->assertInertia(fn (Assert $page) => $page->component('knowledge-base/index'));

        $this->actingAs($user)->get('/knowledge-base/cars')->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/cars/marks')
            ->has('marks', 2)
            ->where('marks.0.name', 'Empty')
            ->where('marks.0.models_count', 1)
            ->where('marks.1.name', 'Acura')
            ->where('marks.1.href', '/knowledge-base/cars/acura')
            ->where('marks.1.logo', '/storage/auto/mark_small/acura.webp')
            ->where('marks.1.logo_dark', '/storage/auto/mark_dark/acura.webp')
            ->where('marks.1.models_count', 4));

        $this->actingAs($user)->get('/knowledge-base/cars/acura')->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/cars/mark')
            ->has('models', 4)
            ->where('models.0.name', 'ADX')
            ->where('models.1.name', 'Integra')
            ->where('models.2.name', 'CDX')
            ->where('models.3.name', 'Legend')
            ->where('models.1.image', '/storage/auto/serie/22/new.jpg'));

        $this->actingAs($user)->get('/knowledge-base/cars/acura/legend')->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/cars/model')
            ->has('generations', 0));

        $this->actingAs($user)->get('/knowledge-base/cars/acura/integra')->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/cars/model')
            ->has('generations', 3)
            ->where('generations.0.name', 'IV')
            ->where('generations.0.series.0.href', $base)
            ->where('generations.1.name', 'III')
            ->has('generations.1.series', 0)
            ->where('generations.2.key', 'g2'));

        $this->actingAs($user)->get($base)->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/cars/series')
            ->where('series.generation.name', 'IV')
            ->has('siblings', 1)
            ->has('modifications', 1)
            ->where('modifications.0.engine', '1.5 / 203 л.с. / бензин')
            ->where('modifications.0.consumption', '7.8 / 6.4')
            ->where('modifications.0.href', $base.'/specifications/23335354')
            ->has('equipments', 1)
            ->where('equipments.0.name', 'Type S')
            ->where('equipments.0.options_count', 2));

        $this->actingAs($user)->get($base.'/specifications/23335354')->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/cars/specifications')
            ->where('modification.name', '1.5 CVT (203 л.с.)')
            ->where('summary.0', ['label' => 'Объем', 'value' => '1.5 л'])
            ->where('dimensions.0', ['label' => 'Длина', 'value' => '4719 мм'])
            ->has('sections', 1)
            ->where('sections.0.name', 'Размеры')
            ->where('sections.0.items.0', ['name' => 'Длина', 'value' => '4719 мм'])
            ->where('equipments.0.href', $base.'/equipment/1'));

        $this->actingAs($user)->get($base.'/equipment/1')->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/cars/equipment')
            ->where('equipment.name', 'Type S')
            ->where('equipments.0.active', true)
            ->where('sections', [['name' => 'Безопасность', 'items' => ['ABS', 'ESP']]]));
    }

    public function test_unknown_or_mismatched_addresses_are_not_found_and_guests_are_redirected(): void
    {
        $user = $this->employee('operator');

        $this->get('/knowledge-base/cars')->assertRedirect(route('login'));
        $this->actingAs($user)->get('/knowledge-base/cars/unknown')->assertNotFound();
        $this->actingAs($user)->get('/knowledge-base/cars/acura/integra/4927341/23335247')->assertNotFound();
        $this->actingAs($user)->get('/knowledge-base/cars/acura/integra/23335197/23335247/specifications/999')->assertNotFound();
        $this->actingAs($user)->get('/knowledge-base/cars/acura/integra/23335197/23335247/equipment/2')->assertNotFound();
    }

    private function createCatalog(): void
    {
        $schema = Schema::connection(CarCatalog::CONNECTION);
        $schema->create('auto_marks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_ru')->nullable();
            $table->string('url');
            $table->string('logo_min')->nullable();
            $table->string('logo_dark')->nullable();
            $table->string('country')->nullable();
            $table->unsignedInteger('ordering')->nullable();
        });
        $schema->create('auto_models', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mark_id');
            $table->string('name');
            $table->string('url');
            $table->string('class')->nullable();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->unsignedInteger('ordering')->nullable();
        });
        $schema->create('auto_generations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('external_id')->nullable();
            $table->string('name');
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
        });
        $schema->create('auto_series', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('generation_id');
            $table->unsignedBigInteger('external_id');
            $table->string('name');
            $table->string('image')->nullable();
        });
        $schema->create('auto_modifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('series_id');
            $table->unsignedBigInteger('external_id');
            $table->string('name');
            $table->decimal('engine_volume', 3, 1)->nullable();
            $table->unsignedSmallInteger('engine_power')->nullable();
            $table->string('engine')->nullable();
            $table->string('transmission')->nullable();
            $table->string('drive')->nullable();
            $table->decimal('consumption_100_km', 4, 1)->nullable();
            $table->decimal('consumption_city', 4, 1)->nullable();
            $table->decimal('consumption_highway', 4, 1)->nullable();
            $table->decimal('acceleration_0_100', 4, 1)->nullable();
        });
        $schema->create('auto_characteristics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedInteger('sort');
        });
        $schema->create('auto_characteristic_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('modification_id');
            $table->unsignedBigInteger('characteristic_id');
            $table->string('value')->nullable();
            $table->string('unit')->nullable();
        });
        $schema->create('auto_equipments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('series_id');
            $table->unsignedBigInteger('modification_id');
            $table->unsignedBigInteger('external_id');
            $table->string('name');
        });
        $schema->create('auto_options', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedInteger('sort');
        });
        $schema->create('auto_option_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('option_id');
            $table->unsignedBigInteger('equipment_id');
        });

        $db = DB::connection(CarCatalog::CONNECTION);
        $db->table('auto_marks')->insert([
            ['id' => 1, 'name' => 'Acura', 'name_ru' => 'Акура', 'url' => 'acura', 'logo_min' => 'mark_small/acura.webp', 'logo_dark' => 'mark_dark/acura.webp', 'country' => 'Япония', 'ordering' => null],
            ['id' => 2, 'name' => 'Empty', 'name_ru' => null, 'url' => 'empty', 'logo_min' => null, 'logo_dark' => null, 'country' => null, 'ordering' => 1],
        ]);
        $db->table('auto_models')->insert([
            ['id' => 1, 'mark_id' => 1, 'name' => 'Integra', 'url' => 'integra', 'class' => 'C', 'year_from' => 1986, 'year_to' => null, 'ordering' => null],
            ['id' => 2, 'mark_id' => 1, 'name' => 'Legend', 'url' => 'legend', 'class' => null, 'year_from' => 1986, 'year_to' => 1996, 'ordering' => null],
            ['id' => 3, 'mark_id' => 2, 'name' => 'Ghost', 'url' => 'ghost', 'class' => null, 'year_from' => null, 'year_to' => null, 'ordering' => null],
            ['id' => 4, 'mark_id' => 1, 'name' => 'CDX', 'url' => 'cdx', 'class' => null, 'year_from' => 2023, 'year_to' => 2024, 'ordering' => 1],
            ['id' => 5, 'mark_id' => 1, 'name' => 'ADX', 'url' => 'adx', 'class' => null, 'year_from' => 2024, 'year_to' => null, 'ordering' => null],
        ]);
        $db->table('auto_generations')->insert([
            ['id' => 1, 'model_id' => 1, 'external_id' => 23335197, 'name' => 'IV', 'year_from' => 2022, 'year_to' => null],
            ['id' => 2, 'model_id' => 1, 'external_id' => null, 'name' => 'I', 'year_from' => 1986, 'year_to' => 1990],
            ['id' => 3, 'model_id' => 1, 'external_id' => 4927341, 'name' => 'III', 'year_from' => 1993, 'year_to' => 2001],
            ['id' => 4, 'model_id' => 4, 'external_id' => 21000001, 'name' => 'I', 'year_from' => 2023, 'year_to' => 2024],
            ['id' => 5, 'model_id' => 5, 'external_id' => 21000002, 'name' => 'I', 'year_from' => 2024, 'year_to' => null],
        ]);
        $db->table('auto_series')->insert([
            ['id' => 1, 'model_id' => 1, 'generation_id' => 1, 'external_id' => 23335247, 'name' => 'Хэтчбек 5 дв.', 'image' => 'serie/22/new.jpg'],
            ['id' => 2, 'model_id' => 1, 'generation_id' => 2, 'external_id' => 20465463, 'name' => 'Хэтчбек 5 дв.', 'image' => 'serie/22/old.jpg'],
            ['id' => 3, 'model_id' => 4, 'generation_id' => 4, 'external_id' => 21000011, 'name' => 'Внедорожник 5 дв.', 'image' => null],
            ['id' => 4, 'model_id' => 5, 'generation_id' => 5, 'external_id' => 21000012, 'name' => 'Внедорожник 5 дв.', 'image' => null],
        ]);
        $db->table('auto_modifications')->insert([
            'id' => 1, 'series_id' => 1, 'external_id' => 23335354, 'name' => '1.5 CVT (203 л.с.)', 'engine_volume' => '1.5', 'engine_power' => 203,
            'engine' => 'бензин', 'transmission' => 'Вариатор', 'drive' => 'Передний', 'consumption_100_km' => '7.1',
            'consumption_city' => '7.8', 'consumption_highway' => '6.4', 'acceleration_0_100' => '7.8',
        ]);
        $db->table('auto_characteristics')->insert([
            ['id' => 1, 'name' => 'Размеры', 'parent_id' => null, 'sort' => 2],
            ['id' => 2, 'name' => 'Длина', 'parent_id' => 1, 'sort' => 1],
            ['id' => 3, 'name' => 'Общая информация', 'parent_id' => null, 'sort' => 1],
        ]);
        $db->table('auto_characteristic_values')->insert(['modification_id' => 1, 'characteristic_id' => 2, 'value' => '4719', 'unit' => 'мм']);
        $db->table('auto_equipments')->insert([
            ['id' => 1, 'series_id' => 1, 'modification_id' => 1, 'external_id' => 24969343, 'name' => 'Type S'],
            ['id' => 2, 'series_id' => 1, 'modification_id' => 1, 'external_id' => 0, 'name' => '1.5 / 203 л.с. / бензин'],
        ]);
        $db->table('auto_options')->insert([
            ['id' => 1, 'name' => 'Безопасность', 'parent_id' => null, 'sort' => 1],
            ['id' => 2, 'name' => 'ESP', 'parent_id' => 1, 'sort' => 2],
            ['id' => 3, 'name' => 'ABS', 'parent_id' => 1, 'sort' => 1],
            ['id' => 4, 'name' => 'Комфорт', 'parent_id' => null, 'sort' => 2],
        ]);
        $db->table('auto_option_values')->insert([
            ['option_id' => 2, 'equipment_id' => 1],
            ['option_id' => 3, 'equipment_id' => 1],
        ]);
    }
}
