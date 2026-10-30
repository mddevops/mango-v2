<?php

namespace Tests\Feature\Sources;

use App\Models\Showroom;
use App\Models\SourceKind;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Upgrades a database that has stage-3 sites to sources. Runs real migrations, so it
 * rebuilds the test schema before and after instead of using a wrapping transaction.
 */
class SourceMigrationTest extends TestCase
{
    /** The first migration of stage 4; the schema before it still has `sites`. */
    private const FIRST_NEW_MIGRATION = '2026_10_20_100000_rename_site_types_to_source_classifications';

    protected function setUp(): void
    {
        parent::setUp();

        $older = collect(glob(database_path('migrations/*.php')) ?: [])
            ->filter(fn (string $path) => strcmp(basename($path, '.php'), self::FIRST_NEW_MIGRATION) < 0)
            ->sort()
            ->values()
            ->all();

        $this->assertFileExists(database_path('migrations/'.self::FIRST_NEW_MIGRATION.'.php'));
        Artisan::call('migrate:fresh', ['--force' => true, '--realpath' => true, '--path' => $older]);
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = true;

        parent::tearDown();
    }

    public function test_sites_become_sources_without_losing_data(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('sites'));

        $now = now();
        $showroom = (object) ['id' => DB::table('showrooms')->insertGetId([
            ...Arr::only(Showroom::factory()->raw(), Schema::getColumnListing('showrooms')),
            'created_at' => $now,
            'updated_at' => $now,
        ])];

        $types = [];

        foreach ([['advertising', 'Реклама'], ['seo', 'SEO'], ['advertising-seo', 'Реклама/SEO'], ['classified', 'Классифайд'], ['va', 'VA'], ['marketpleys', 'Маркетплейс']] as $index => [$code, $name]) {
            DB::table('site_types')->updateOrInsert(['code' => $code], ['name' => $name, 'sort_order' => $index * 10, 'active' => true, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $types[$code] = (int) DB::table('site_types')->where('code', $code)->value('id');
        }

        $agency = DB::table('agencies')->insertGetId(['name' => 'Агентство', 'active' => true, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now]);

        $site = fn (string $name, string $type, array $extra = []) => DB::table('sites')->insertGetId([
            'showroom_id' => $showroom->id,
            'site_type_id' => $types[$type],
            'agency_id' => null,
            'name' => $name,
            'url' => null,
            'note' => null,
            'active' => true,
            'lock_version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            ...$extra,
        ]);

        $advertising = $site('Рекламный сайт', 'advertising', ['agency_id' => $agency, 'url' => 'https://example.ru', 'note' => 'Заметка', 'lock_version' => 3, 'active' => false]);
        $classified = $site('Авито', 'classified');
        $va = $site('Сайт VA', 'va');
        $custom = $site('Маркетплейс', 'marketpleys');
        $first = $site('Дубль', 'seo');
        $second = $site('Дубль', 'seo');

        $permission = DB::table('permissions')->insertGetId(['name' => 'sites.view', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        $role = DB::table('roles')->insertGetId(['name' => 'director', 'label' => 'Руководитель', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('role_has_permissions')->insert(['permission_id' => $permission, 'role_id' => $role]);

        Artisan::call('migrate', ['--force' => true]);

        $kinds = DB::table('source_kinds')->pluck('id', 'code');
        $sources = DB::table('sources')->get()->keyBy('id');

        $this->assertCount(6, $sources);
        $row = $sources[$advertising];
        $this->assertSame([$showroom->id, $agency, 'https://example.ru', 'Заметка', 3, 0], [
            (int) $row->showroom_id, (int) $row->agency_id, $row->url, $row->note, (int) $row->lock_version, (int) $row->active,
        ]);
        $this->assertSame([$kinds['site'], $types['advertising'], $types['advertising']], [(int) $row->source_kind_id, (int) $row->source_classification_id, (int) $row->legacy_classification_id]);

        $this->assertSame((int) $kinds['classified'], (int) $sources[$classified]->source_kind_id);
        $this->assertNull($sources[$classified]->source_classification_id);
        $this->assertSame($types['classified'], (int) $sources[$classified]->legacy_classification_id);

        $this->assertSame([$kinds['site'], $types['va']], [(int) $sources[$va]->source_kind_id, (int) $sources[$va]->source_classification_id]);
        $this->assertSame([$kinds['site'], $types['marketpleys']], [(int) $sources[$custom]->source_kind_id, (int) $sources[$custom]->source_classification_id]);
        $this->assertSame(['dubl', 'dubl-2'], [$sources[$first]->code, $sources[$second]->code]);
        $this->assertSame('reklamnyy-sayt', $row->code);

        $classifiedRow = DB::table('source_classifications')->where('code', 'classified')->first();
        $this->assertNotNull($classifiedRow);
        $this->assertSame($types['classified'], (int) $classifiedRow->id);
        $this->assertSame(0, (int) $classifiedRow->active);
        $this->assertSame($types['va'], (int) DB::table('source_classifications')->where('code', 'va')->value('id'));

        $this->assertSame(['sources.view'], DB::table('permissions')->where('id', $permission)->pluck('name')->all());
        $this->assertTrue(DB::table('role_has_permissions')->where(['permission_id' => $permission, 'role_id' => $role])->exists());
    }

    public function test_reference_seeder_is_idempotent_and_keeps_user_changes(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        $this->seed(ReferenceDataSeeder::class);

        $counts = fn () => [
            DB::table('source_kinds')->count(),
            DB::table('contact_channels')->count(),
            DB::table('source_classifications')->count(),
            DB::table('source_kind_classification')->count(),
            DB::table('source_kind_channel')->count(),
        ];

        $before = $counts();
        $this->assertSame([8, 4, 4, 6, 22], $before);

        $site = SourceKind::query()->where('code', 'site')->firstOrFail();
        $site->forceFill(['name' => 'Веб-сайт', 'active' => false])->save();
        $site->channels()->detach(DB::table('contact_channels')->where('code', 'chat')->value('id'));

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $site->refresh();
        $this->assertSame('Веб-сайт', $site->name);
        $this->assertFalse($site->active);
        $this->assertSame(2, $site->channels()->count());
        $this->assertSame([8, 4, 4, 6, 21], $counts());
        $this->assertFalse((bool) DB::table('contact_channels')->where('code', 'form')->value('active'));
    }
}
