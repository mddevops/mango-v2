<?php

namespace Tests\Feature\Sources;

use App\Models\Showroom;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Merges the mistaken channel «Форма/заявка» into the callback channel on a database
 * that already has kind rules and sources using it. Runs real migrations, so it
 * rebuilds the test schema before and after instead of using a wrapping transaction.
 */
class ContactChannelMergeMigrationTest extends TestCase
{
    private const MIGRATION = '2026_10_20_100500_merge_form_channel_into_callback.php';

    protected function setUp(): void
    {
        parent::setUp();

        $before = collect(glob(database_path('migrations/*.php')) ?: [])
            ->reject(fn (string $path) => basename($path) === self::MIGRATION)
            ->values()
            ->all();

        Artisan::call('migrate:fresh', ['--force' => true, '--path' => $before, '--realpath' => true]);
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = true;

        parent::tearDown();
    }

    public function test_form_links_move_to_callback_without_duplicates(): void
    {
        $now = now();
        $channels = DB::table('contact_channels')->pluck('id', 'code');
        $kinds = DB::table('source_kinds')->pluck('id', 'code');
        $callbackBefore = DB::table('contact_channels')->where('code', 'callback-request')->first();

        $this->assertSame('Запрос обратного звонка', $callbackBefore->name);
        $this->assertTrue((bool) DB::table('contact_channels')->where('code', 'form')->value('active'));

        $messenger = DB::table('contact_channels')->insertGetId([
            'code' => 'messenger', 'name' => 'Мессенджер', 'sort_order' => 50, 'active' => true, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('source_kind_channel')->insert(['source_kind_id' => $kinds['bot'], 'contact_channel_id' => $messenger]);
        $channels['messenger'] = $messenger;

        $showroom = Showroom::factory()->create();
        $source = fn (string $code, array $channelCodes) => tap(DB::table('sources')->insertGetId([
            'code' => $code, 'showroom_id' => $showroom->id, 'source_kind_id' => $kinds['site'], 'name' => $code,
            'active' => true, 'lock_version' => 2, 'created_at' => $now, 'updated_at' => $now,
        ]), fn (int $id) => DB::table('source_channel')->insert(
            collect($channelCodes)->map(fn (string $channel) => ['source_id' => $id, 'contact_channel_id' => $channels[$channel]])->all(),
        ));

        $formOnly = $source('form-only', ['form']);
        $both = $source('both', ['callback-request', 'form', 'chat']);
        $untouched = $source('untouched', ['chat', 'messenger']);

        Artisan::call('migrate', ['--force' => true]);

        $callback = DB::table('contact_channels')->where('code', 'callback-request')->first();
        $this->assertSame([(int) $callbackBefore->id, 'Обратный звонок', (int) $callbackBefore->lock_version + 1], [(int) $callback->id, $callback->name, (int) $callback->lock_version]);

        $form = DB::table('contact_channels')->where('code', 'form')->first();
        $this->assertNotNull($form);
        $this->assertSame([(int) $channels['form'], 0], [(int) $form->id, (int) $form->active]);
        $this->assertFalse(DB::table('source_kind_channel')->where('contact_channel_id', $form->id)->exists());
        $this->assertFalse(DB::table('source_channel')->where('contact_channel_id', $form->id)->exists());

        $kindChannels = fn (string $kind) => DB::table('source_kind_channel')->where('source_kind_id', $kinds[$kind])->orderBy('contact_channel_id')->pluck('contact_channel_id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([(int) $callback->id], $kindChannels('quiz'));
        $this->assertSame([(int) $callback->id, (int) $channels['direct-call'], (int) $channels['chat']], $kindChannels('site'));
        $this->assertSame([(int) $callback->id, (int) $channels['direct-call'], (int) $channels['chat'], $messenger], $kindChannels('bot'));

        $sourceChannels = fn (int $id) => DB::table('source_channel')->where('source_id', $id)->orderBy('contact_channel_id')->pluck('contact_channel_id')->map(fn ($channel) => (int) $channel)->all();
        $this->assertSame([(int) $callback->id], $sourceChannels($formOnly));
        $this->assertSame([(int) $callback->id, (int) $channels['chat']], $sourceChannels($both));
        $this->assertSame([(int) $channels['chat'], $messenger], $sourceChannels($untouched));

        $locks = DB::table('sources')->pluck('lock_version', 'id');
        $this->assertSame([3, 3, 2], [(int) $locks[$formOnly], (int) $locks[$both], (int) $locks[$untouched]]);

        $this->assertSame(
            ['Мессенджер', 1, 0],
            [DB::table('contact_channels')->where('id', $messenger)->value('name'), (int) DB::table('contact_channels')->where('id', $messenger)->value('active'), (int) DB::table('contact_channels')->where('id', $messenger)->value('lock_version')],
        );

        $count = DB::table('contact_channels')->count();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->assertSame($count, DB::table('contact_channels')->count());
        $this->assertSame(0, (int) DB::table('contact_channels')->where('code', 'form')->value('active'));
        $this->assertFalse(DB::table('source_kind_channel')->where('contact_channel_id', $form->id)->exists());
    }

    public function test_rename_stops_when_another_channel_already_has_the_name(): void
    {
        $now = now();
        DB::table('contact_channels')->insert([
            'code' => 'custom-callback', 'name' => 'Обратный звонок', 'sort_order' => 60, 'active' => true, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            Artisan::call('migrate', ['--force' => true]);
        } finally {
            $this->assertSame('Запрос обратного звонка', DB::table('contact_channels')->where('code', 'callback-request')->value('name'));
            $this->assertTrue((bool) DB::table('contact_channels')->where('code', 'form')->value('active'));
        }
    }
}
