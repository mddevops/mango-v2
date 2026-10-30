<?php

namespace Tests\Feature\Mango;

use App\Enums\CallRecordingState;
use App\Jobs\ProcessMangoEvent;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\CallRecording;
use App\Models\IntegrationConnection;
use App\Models\MangoEvent;
use App\Models\Order;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\User;
use App\Support\PermissionCatalog as P;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Mango\Concerns\BuildsMangoCalls;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

/**
 * Recording events follow 3.1.4 and 3.1.8, playback follows 3.5.2 of MangoOffice_VPBX_API
 * v1.9: `GET queries/recording/link/{id}/play/{key}/{timestamp}/{sign}` answers 302 to a
 * one-time link of files.mango-office.ru, whose answer is streamed to the browser. Every
 * HTTP exchange is a fake; nothing is stored.
 */
class MangoCallRecordingTest extends TestCase
{
    use BuildsMangoCalls;
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    private const API = 'https://app.mango-office.ru/vpbx/queries/recording/link/';

    private const LINK = 'https://files.mango-office.ru/one-time-secret-link';

    private User $admin;

    private Showroom $showroom;

    private Source $source;

    private IntegrationConnection $connection;

    /** @var list<string> */
    private array $logs = [];

    /** @var list<array{url: string, method: string, range: string|null, options: array<string, mixed>, headers: string}> */
    private array $sent = [];

    private bool $faked = false;

    private ?Closure $apiAnswer = null;

    private ?Closure $fileAnswer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
        $this->admin = $this->administrator(['name' => 'Админ']);
        $this->travelTo(CarbonImmutable::parse('2026-10-19 12:05', 'Europe/Moscow'));

        $this->showroom = Showroom::factory()->create(['timezone' => 'Europe/Moscow']);
        $this->source = $this->makeSource($this->showroom, ['name' => 'Сайт салона']);
        $this->connection = $this->mangoConnection($this->showroom);
        $this->bindLine($this->connection, $this->source, self::LINE);
        $this->extension($this->connection, '123', $this->mangoEmployee('Оператор', [$this->showroom]));

        config(['inertia.ssr.enabled' => false]);
        Queue::fakeExcept([ProcessMangoEvent::class]);
        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logs[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
    }

    public function test_recording_events_keep_metadata_only_and_start_no_download(): void
    {
        $started = $this->recordingEvent('rec-1', 'entry-1', 1, 'Started');

        $this->mangoEvent($this->connection, 'recording', $started, salt: 'wrong-salt')->assertForbidden();
        $this->assertSame(0, MangoEvent::query()->count());

        $this->mangoEvent($this->connection, 'recording', $started)->assertOk();
        $this->mangoEvent($this->connection, 'recording', $started)->assertOk();

        $event = MangoEvent::query()->sole();
        $this->assertSame([MangoEvent::RECORDING, 'entry-1', 'entry-1:abonent', 1], [$event->kind, $event->entry_id, $event->call_id, $event->seq]);
        $this->assertSame(CallRecordingState::Pending, CallRecording::query()->sole()->state);

        $completed = $this->recordingEvent('rec-1', 'entry-1', 2, 'Completed', ['completion_code' => 1000, 'recipient' => 'Cloud']);
        $this->mangoEvent($this->connection, 'recording', $completed)->assertOk();
        $this->mangoEvent($this->connection, 'recording', $completed)->assertOk();
        $this->mangoEvent($this->connection, 'record/added', $this->addedEvent('rec-1', 'entry-1'))->assertOk();
        $this->mangoEvent($this->connection, 'record/added', $this->addedEvent('rec-1', 'entry-1'))->assertOk();

        $recording = CallRecording::query()->sole();
        $this->assertSame([CallRecordingState::Recorded, 'Запись в Mango', 2, 1000, 'Cloud'], [$recording->state, $recording->state->label(), $recording->last_seq, $recording->completion_code, $recording->recipient]);
        $this->assertNotNull($recording->added_at);
        $this->assertSame([null, null, null], [$recording->file_path, $recording->claim_token, $recording->available_at]);
        $this->assertSame(3, MangoEvent::query()->count());
        $this->assertSame([0, 0], [Order::query()->count(), Call::query()->count()]);

        // Mango did not save it, or sent it by e-mail only; a later record/added wins.
        $this->mangoEvent($this->connection, 'recording', $this->recordingEvent('rec-short', 'entry-1', 2, 'Completed', ['completion_code' => 4002]))->assertOk();
        $this->mangoEvent($this->connection, 'recording', $this->recordingEvent('rec-mail', 'entry-1', 2, 'Completed', ['completion_code' => 1000, 'recipient' => 'Mail']))->assertOk();
        $this->assertSame([CallRecordingState::Unavailable, 'not_saved_by_mango'], [$this->recording('rec-short')->state, $this->recording('rec-short')->error_code]);
        $this->assertSame([CallRecordingState::Unavailable, 'mail_only'], [$this->recording('rec-mail')->state, $this->recording('rec-mail')->error_code]);

        $this->mangoEvent($this->connection, 'record/added', $this->addedEvent('rec-mail', 'entry-1'))->assertOk();
        $this->assertSame([CallRecordingState::Recorded, null], [$this->recording('rec-mail')->state, $this->recording('rec-mail')->error_code]);

        // Neither a request to Mango nor a queued download follows an event.
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('jobs')->count());

        $this->actingAs($this->admin)->getJson(route('connections.mango.show', $this->connection->id))
            ->assertOk()
            ->assertJsonPath('recordings.counts.recorded', 2)
            ->assertJsonPath('recordings.counts.unavailable', 1)
            ->assertJsonPath('recordings.problems.0.error_code', 'not_saved_by_mango')
            ->assertJsonMissingPath('recordings.problems.0.recording_id')
            ->assertJsonMissingPath('recordings.problems.0.audio_url');
    }

    public function test_recording_before_its_call_is_linked_later_and_one_call_keeps_several_recordings(): void
    {
        $this->mangoEvent($this->connection, 'record/added', $this->addedEvent('rec-a', 'entry-2'))->assertOk();
        $this->assertNull(CallRecording::query()->sole()->call_id);
        $this->assertSame(0, Order::query()->count());

        $order = $this->callWithOrder('entry-2');
        $call = Call::query()->sole();
        $this->assertSame($call->id, $this->recording('rec-a')->call_id);

        $this->mangoEvent($this->connection, 'recording', $this->recordingEvent('rec-b', 'entry-2', 1, 'Started'))->assertOk();
        $this->assertSame([$call->id, $call->id], CallRecording::query()->orderBy('id')->pluck('call_id')->all());
        $this->assertSame(1, Order::query()->count());

        $this->actingAs($this->admin)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.recordings', true)
                ->reloadOnly('calls', fn (Assert $reload) => $reload
                    ->has('calls.data.0.recordings', 2)
                    ->where('calls.data.0.recordings.0.label', 'Запись 1')
                    ->where('calls.data.0.recordings.0.state_label', 'Запись в Mango')
                    ->where('calls.data.0.recordings.0.audio_url', route('orders.recordings.audio', [$order->id, $this->recording('rec-a')->id], false))
                    ->where('calls.data.0.recordings.1.state_label', 'Запись не завершена')
                    ->where('calls.data.0.recordings.1.audio_url', null)
                    ->missing('calls.data.0.recordings.0.recording_id')));

        Http::assertNothingSent();
    }

    public function test_the_same_recording_id_in_two_connections_stays_two_recordings(): void
    {
        $other = $this->mangoConnection($this->showroom, 'Mango второй', 'other-key', 'other-salt');

        $this->mangoEvent($this->connection, 'record/added', $this->addedEvent('rec-same', 'entry-3'))->assertOk();
        $this->mangoEvent($other, 'record/added', $this->addedEvent('rec-same', 'entry-3'), 'other-key', 'other-salt')->assertOk();

        $this->assertSame(
            [$this->connection->id, $other->id],
            CallRecording::query()->where('recording_id', 'rec-same')->orderBy('id')->pluck('integration_connection_id')->all(),
        );
    }

    public function test_playback_signs_a_fresh_get_link_and_streams_the_one_time_file_link(): void
    {
        $order = $this->callWithOrder('entry-4');
        $recording = $this->addedRecording('MToxMDA=/rec+4', 'entry-4');
        $this->fakeMango();
        $url = route('orders.recordings.audio', [$order->id, $recording->id]);

        $response = $this->actingAs($this->admin)->get($url)->assertOk();

        $this->assertSame($this->mp3(), $this->body($response));
        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        $this->assertSame((string) strlen($this->mp3()), $response->headers->get('Content-Length'));
        $this->assertSame('none', $response->headers->get('Accept-Ranges'), 'Seeking is not promised without Mango saying so.');
        $this->assertNull($response->headers->get('Content-Range'));
        $this->assertPrivate($response);

        // 3.5.2: the timestamp is the Unix expiry time, here now + 300 s; sign = sha256(key + timestamp + recording_id + salt).
        $expires = CarbonImmutable::now()->getTimestamp() + 300;
        $sign = hash('sha256', 'test-vpbx-key'.$expires.'MToxMDA=/rec+4'.'test-vpbx-salt');
        $this->assertCount(2, $this->sent);
        $this->assertSame(self::API.'MToxMDA%3D%2Frec%2B4/play/test-vpbx-key/'.$expires.'/'.$sign, $this->sent[0]['url']);
        $this->assertSame(['GET', null], [$this->sent[0]['method'], $this->sent[0]['range']]);
        $this->assertSame([self::LINK, 'GET'], [$this->sent[1]['url'], $this->sent[1]['method']]);
        $this->assertStringNotContainsString('test-vpbx-key', $this->sent[1]['headers']);

        foreach ($this->sent as $request) {
            $this->assertTrue($request['options']['stream'] ?? false, 'The body is the network stream, not a buffer.');
            $this->assertArrayNotHasKey('sink', $request['options']);
            $this->assertFalse($request['options']['allow_redirects'] ?? true, 'Redirects are never followed by the client.');
        }

        // Each request signs its own link and asks for a new one-time file link.
        config(['services.mango.recordings.link_ttl' => 120]);
        $this->travel(10)->seconds();
        $this->get($url)->assertOk();
        $this->assertCount(4, $this->sent);
        $this->assertStringContainsString('/'.(CarbonImmutable::now()->getTimestamp() + 120).'/', $this->sent[2]['url']);

        $this->assertSecretsNotLeaked([$response]);
    }

    public function test_a_large_stream_is_passed_in_pieces_without_files_or_a_whole_body_in_memory(): void
    {
        $order = $this->callWithOrder('entry-5');
        $recording = $this->addedRecording('rec-big', 'entry-5');
        $audio = 'ID3'."\x04\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x90\x64", 512 * 1024);
        $inner = Utils::streamFor($audio);
        $reads = [];
        $before = $this->storageFiles();

        $this->fakeMango(file: function () use ($inner, &$reads) {
            return Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'audio/mpeg'], FnStream::decorate($inner, [
                'read' => function (int $length) use ($inner, &$reads) {
                    $reads[] = $length;

                    return $inner->read($length);
                },
                'getContents' => fn () => throw new LogicException('The whole body was read at once.'),
                '__toString' => fn () => throw new LogicException('The whole body was read at once.'),
            ])));
        });

        $response = $this->actingAs($this->admin)->get(route('orders.recordings.audio', [$order->id, $recording->id]))->assertOk();
        $this->assertNull($response->headers->get('Content-Length'));

        $this->assertSame(strlen($audio), strlen($this->body($response)));
        $this->assertGreaterThan(30, count($reads));
        $this->assertLessThanOrEqual(65536, max($reads));
        $this->assertSame($before, $this->storageFiles(), 'Nothing is written to storage.');
        $this->assertSame(0, DB::table('call_recordings')->whereNotNull('file_path')->count());
    }

    public function test_range_is_forwarded_to_the_file_service_and_its_answer_is_passed_through(): void
    {
        $order = $this->callWithOrder('entry-6');
        $recording = $this->addedRecording('rec-range', 'entry-6');
        $url = route('orders.recordings.audio', [$order->id, $recording->id]);
        $size = strlen($this->mp3());

        $this->fakeMango(file: fn (HttpRequest $request) => match ($request->header('Range')[0] ?? null) {
            'bytes=0-9' => Http::response(substr($this->mp3(), 0, 10), 206, ['Content-Type' => 'audio/mpeg', 'Content-Length' => '10', 'Content-Range' => "bytes 0-9/{$size}"]),
            'bytes=1000-' => Http::response(substr($this->mp3(), 1000), 206, ['Content-Type' => 'audio/mpeg', 'Content-Length' => (string) ($size - 1000), 'Content-Range' => 'bytes 1000-'.($size - 1)."/{$size}"]),
            'bytes=99999-' => Http::response('', 416, ['Content-Range' => "bytes */{$size}"]),
            default => Http::response($this->mp3(), 200, ['Content-Type' => 'audio/mpeg', 'Content-Length' => (string) $size]),
        });
        $this->actingAs($this->admin);

        $partial = $this->get($url, ['Range' => 'bytes=0-9'])->assertStatus(206);
        $this->assertSame(["bytes 0-9/{$size}", '10', 'audio/mpeg', 'bytes'], [$partial->headers->get('Content-Range'), $partial->headers->get('Content-Length'), $partial->headers->get('Content-Type'), $partial->headers->get('Accept-Ranges')]);
        $this->assertSame(substr($this->mp3(), 0, 10), $this->body($partial));
        $this->assertPrivate($partial);

        $seek = $this->get($url, ['Range' => 'bytes=1000-'])->assertStatus(206);
        $this->assertSame(substr($this->mp3(), 1000), $this->body($seek));

        $outside = $this->get($url, ['Range' => 'bytes=99999-'])->assertStatus(416);
        $this->assertSame("bytes */{$size}", $outside->headers->get('Content-Range'));
        $this->assertSame('', $this->body($outside));

        // Several ranges or a malformed one are not forwarded: the whole file comes as 200.
        $this->get($url, ['Range' => 'bytes=0-1,5-9'])->assertOk();
        $this->get($url, ['Range' => 'bytes=9-1'])->assertOk();

        // The file service ignores Range: its 200 is not turned into a 206.
        $this->fakeMango();
        $ignored = $this->get($url, ['Range' => 'bytes=0-9'])->assertOk();
        $this->assertNull($ignored->headers->get('Content-Range'));
        $this->assertSame('none', $ignored->headers->get('Accept-Ranges'));
        $this->assertSame($this->mp3(), $this->body($ignored));

        $api = array_values(array_filter($this->sent, fn (array $request) => str_starts_with($request['url'], self::API)));
        $files = array_values(array_filter($this->sent, fn (array $request) => $request['url'] === self::LINK));
        $this->assertCount(6, $api, 'Every request gets its own fresh link.');
        $this->assertSame([null], array_values(array_unique(array_column($api, 'range'))), 'Range goes only to the file service.');
        $this->assertSame(['bytes=0-9', 'bytes=1000-', 'bytes=99999-', null, null, 'bytes=0-9'], array_column($files, 'range'));
    }

    public function test_head_only_asks_mango_for_a_link_and_never_spends_a_file_link(): void
    {
        $order = $this->callWithOrder('entry-7');
        $recording = $this->addedRecording('rec-head', 'entry-7');
        $this->fakeMango();
        $url = route('orders.recordings.audio', [$order->id, $recording->id]);

        $head = $this->actingAs($this->admin)->call('HEAD', $url)->assertOk();
        $this->assertSame('', $this->body($head));
        $this->assertPrivate($head);
        $this->assertSame([self::API], array_map(fn (array $request) => substr($request['url'], 0, strlen(self::API)), $this->sent));

        $this->assertSame($this->mp3(), $this->body($this->get($url)->assertOk()));
        $this->assertSame(1, count(array_filter($this->sent, fn (array $request) => $request['url'] === self::LINK)));
    }

    public function test_only_documented_mango_file_hosts_are_requested(): void
    {
        $order = $this->callWithOrder('entry-8');
        $recording = $this->addedRecording('rec-redirect', 'entry-8');
        $url = route('orders.recordings.audio', [$order->id, $recording->id]);
        $this->actingAs($this->admin);

        foreach ([
            'https://evil.example/files.mango-office.ru/x',
            'http://files.mango-office.ru/x',
            'https://files.mango-office.ru@127.0.0.1/x',
            'https://user:pass@files.mango-office.ru/x',
            'https://files.mango-office.ru:8443/x',
            '/relative/x',
        ] as $location) {
            $this->fakeMango(fn () => Http::response('', 302, ['Location' => $location]));
            $this->get($url)->assertStatus(502)->assertSeeText('Не удалось воспроизвести');
        }

        // The file service redirects again: the redirect is not followed.
        $this->fakeMango(file: fn () => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest']));
        $response = $this->get($url)->assertStatus(502);
        $this->assertNull($response->headers->get('Location'));

        $this->assertSame([], array_values(array_filter(
            array_map(fn (array $request) => parse_url($request['url'], PHP_URL_HOST), $this->sent),
            fn (?string $host) => ! in_array($host, ['app.mango-office.ru', 'files.mango-office.ru'], true),
        )));
        $this->assertCount(1, array_filter($this->sent, fn (array $request) => $request['url'] === self::LINK));
    }

    public function test_an_unavailable_recording_and_mango_errors_give_safe_messages_without_secrets(): void
    {
        $order = $this->callWithOrder('entry-9');
        $recording = $this->addedRecording('rec-error', 'entry-9');
        $url = route('orders.recordings.audio', [$order->id, $recording->id]);
        $this->actingAs($this->admin);
        $responses = [];

        $cases = [
            [fn () => Http::response(['result' => 3320], 200), null, 404, 'Запись пока недоступна'],
            [null, fn () => Http::response('Not found', 404, ['Content-Type' => 'text/plain']), 404, 'Запись пока недоступна'],
            [fn () => Http::response(['result' => 3102], 403), null, 502, 'Не удалось воспроизвести'],
            [fn () => Http::response('<html>Bad gateway</html>', 502, ['Content-Type' => 'text/html']), null, 502, 'Не удалось воспроизвести'],
            [fn () => Http::failedConnection('cURL error 28: timed out for '.self::API.'secret'), null, 502, 'Не удалось воспроизвести'],
            [null, fn () => Http::response('<html>login</html>', 200, ['Content-Type' => 'text/html']), 502, 'Не удалось воспроизвести'],
            [null, fn () => Http::response('{"result":3320}', 200, ['Content-Type' => 'audio/mpeg']), 502, 'Не удалось воспроизвести'],
            [null, fn () => Http::response('', 200, ['Content-Type' => 'audio/mpeg']), 502, 'Не удалось воспроизвести'],
            [null, fn () => Http::failedConnection('cURL error 28: timed out for '.self::LINK), 502, 'Не удалось воспроизвести'],
        ];

        foreach ($cases as [$api, $file, $status, $message]) {
            $this->fakeMango($api, $file);
            $response = $this->get($url)->assertStatus($status);
            $this->assertSame($message, $this->body($response));
            $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'), 'An error is never sent as audio.');
            $this->assertPrivate($response);
            $responses[] = $response;
        }

        $this->fakeMango(fn () => Http::response(['result' => 3320], 200));
        $this->call('HEAD', $url)->assertNotFound();

        // A disabled connection: Mango is not asked at all.
        $this->connection->forceFill(['active' => false])->save();
        $count = count($this->sent);
        $this->get($url)->assertStatus(502);
        $this->assertCount($count, $this->sent);

        $this->assertSame(CallRecordingState::Recorded, $recording->fresh()->state, 'Playback never changes the recording.');
        $this->assertSecretsNotLeaked($responses);
        $this->assertNotEmpty($this->logs);
    }

    public function test_listening_needs_the_order_history_and_recordings_permissions_on_every_request(): void
    {
        $order = $this->callWithOrder('entry-20');
        $recording = $this->addedRecording('rec-play', 'entry-20');
        $this->fakeMango();
        $url = route('orders.recordings.audio', [$order->id, $recording->id]);

        $listener = $this->mangoEmployee('Слушатель', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_HISTORY, P::ORDERS_RECORDINGS]);
        $this->assertSame($this->mp3(), $this->body($this->actingAs($listener)->get($url)->assertOk()));
        $allowed = count($this->sent);

        $withoutRecordings = $this->mangoEmployee('Без записей', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_HISTORY]);
        $this->actingAs($withoutRecordings)->get($url)->assertForbidden();
        $this->actingAs($withoutRecordings)->call('HEAD', $url)->assertForbidden();
        $this->actingAs($withoutRecordings)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.recordings', false)
                ->reloadOnly('calls', fn (Assert $reload) => $reload->has('calls.data', 1)->missing('calls.data.0.recordings')));

        $withoutHistory = $this->mangoEmployee('Без истории', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_RECORDINGS]);
        $this->actingAs($withoutHistory)->get($url)->assertForbidden();

        $elsewhere = $this->mangoEmployee('Другой салон', [Showroom::factory()->create()], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_HISTORY, P::ORDERS_RECORDINGS]);
        $this->actingAs($elsewhere)->get($url)->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect(route('login'));

        $this->assertCount($allowed, $this->sent, 'A denied request never reaches Mango.');
    }

    public function test_a_foreign_unlinked_or_unsaved_recording_is_not_played_through_an_order(): void
    {
        $order = $this->callWithOrder('entry-21');
        $other = $this->callWithOrder('entry-22', '79001112233');
        $foreign = $this->addedRecording('rec-foreign', 'entry-22');
        $unlinked = $this->addedRecording('rec-orphan', 'entry-no-call');
        $this->mangoEvent($this->connection, 'recording', $this->recordingEvent('rec-started', 'entry-21', 1, 'Started'))->assertOk();
        $this->mangoEvent($this->connection, 'recording', $this->recordingEvent('rec-lost', 'entry-21', 2, 'Completed', ['completion_code' => 4002]))->assertOk();
        $this->assertNull($unlinked->call_id);
        $this->assertNotSame($order->id, $other->id);
        $this->fakeMango();
        $this->actingAs($this->admin);

        foreach ([$foreign, $unlinked, $this->recording('rec-started'), $this->recording('rec-lost')] as $recording) {
            $this->get(route('orders.recordings.audio', [$order->id, $recording->id]))->assertNotFound();
            $this->postJson(route('orders.recordings.play', [$order->id, $recording->id]), ['playback' => fake()->uuid()])->assertNotFound();
        }

        $this->assertSame([], $this->sent);
        $this->get(route('orders.recordings.audio', [$other->id, $foreign->id]))->assertOk();
    }

    public function test_listening_start_is_audited_once_per_playback_and_playback_leaves_the_order_unchanged(): void
    {
        $order = $this->callWithOrder('entry-23');
        $before = $this->snapshot($order);
        $this->assertNotNull($before['responsible_id']);
        $this->travel(5)->minutes();

        $recording = $this->addedRecording('rec-audit', 'entry-23');
        $this->mangoEvent($this->connection, 'recording', $this->recordingEvent('rec-audit', 'entry-23', 3, 'Completed', ['completion_code' => 1000, 'recipient' => 'Cloud']))->assertOk();
        $this->fakeMango(file: fn (HttpRequest $request) => isset($request->header('Range')[0])
            ? Http::response(substr($this->mp3(), 0, 100), 206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-99/'.strlen($this->mp3())])
            : Http::response($this->mp3(), 200, ['Content-Type' => 'audio/mpeg']));
        $playUrl = route('orders.recordings.play', [$order->id, $recording->id]);
        $audioUrl = route('orders.recordings.audio', [$order->id, $recording->id]);
        $playback = fake()->uuid();

        $this->actingAs($this->admin)->postJson($playUrl, ['playback' => $playback])->assertOk()->assertJsonPath('recorded', true);
        $this->body($this->get($audioUrl, ['Range' => 'bytes=0-99'])->assertStatus(206));
        $this->body($this->get($audioUrl)->assertOk());
        $this->call('HEAD', $audioUrl)->assertOk();
        $this->postJson($playUrl, ['playback' => $playback])->assertOk()->assertJsonPath('recorded', false);
        $this->postJson($playUrl, ['playback' => 'not-a-uuid'])->assertUnprocessable();

        $audit = AuditLog::query()->where('event', 'order.recording_played')->sole();
        $this->assertSame([$this->admin->id, 'order', $order->id], [$audit->actor_id, $audit->subject_type, $audit->subject_id]);
        $this->assertSame(['recording' => "№{$recording->id}"], $audit->changes);

        $this->postJson($playUrl, ['playback' => fake()->uuid()])->assertOk()->assertJsonPath('recorded', true);
        $this->assertSame(2, AuditLog::query()->where('event', 'order.recording_played')->count());

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(1, Order::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Order $order): array
    {
        $fresh = Order::query()->findOrFail($order->id);

        return [
            'responsible_id' => $fresh->responsible_id,
            'order_status_id' => $fresh->order_status_id,
            'source_id' => $fresh->source_id,
            'business_date' => $fresh->business_date,
            'repeat_number' => $fresh->repeat_number,
            'lock_version' => $fresh->lock_version,
            'updated_at' => $fresh->updated_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function recordingEvent(string $recordingId, string $entry, int $seq, string $state, array $extra = []): array
    {
        return [
            'recording_id' => $recordingId,
            'recording_state' => $state,
            'seq' => $seq,
            'call_id' => "{$entry}:abonent",
            'entry_id' => $entry,
            'extension' => '123',
            'timestamp' => (string) $this->at(40 + $seq),
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addedEvent(string $recordingId, string $entry): array
    {
        return ['entry_id' => $entry, 'product_id' => 300022532, 'user_id' => -1, 'timestamp' => $this->at(60), 'recording_id' => $recordingId];
    }

    private function addedRecording(string $recordingId, string $entry): CallRecording
    {
        $this->mangoEvent($this->connection, 'record/added', $this->addedEvent($recordingId, $entry))->assertOk();

        return $this->recording($recordingId);
    }

    private function recording(string $recordingId): CallRecording
    {
        return CallRecording::query()->where('recording_id', $recordingId)->sole();
    }

    private function callWithOrder(string $entry, string $client = self::CLIENT): Order
    {
        foreach ($this->routedCall($entry, client: $client) as $event) {
            $this->mangoEvent($this->connection, 'call', $event)->assertOk();
        }

        $this->mangoEvent($this->connection, 'summary', $this->summary($entry, overrides: ['from' => ['number' => $client]]))->assertOk();

        return Order::query()->findOrFail(Call::query()->where('entry_id', $entry)->value('order_id'));
    }

    /**
     * Records every request with its options. Without an answer of its own the API gives
     * a 302 to the file link and the file service the whole file.
     */
    private function fakeMango(?Closure $api = null, ?Closure $file = null): void
    {
        $this->apiAnswer = $api;
        $this->fileAnswer = $file;

        if ($this->faked) {
            return;
        }

        $this->faked = true;
        Http::fake(function (HttpRequest $request, array $options) {
            $this->sent[] = [
                'url' => $request->url(),
                'method' => $request->method(),
                'range' => $request->header('Range')[0] ?? null,
                'options' => $options,
                'headers' => (string) json_encode($request->headers(), JSON_UNESCAPED_SLASHES),
            ];

            if (str_starts_with($request->url(), self::API)) {
                return $this->apiAnswer !== null ? ($this->apiAnswer)($request) : Http::response('', 302, ['Location' => self::LINK]);
            }

            if (parse_url($request->url(), PHP_URL_HOST) === 'files.mango-office.ru') {
                return $this->fileAnswer !== null ? ($this->fileAnswer)($request) : Http::response($this->mp3(), 200, ['Content-Type' => 'audio/mpeg', 'Content-Length' => (string) strlen($this->mp3())]);
            }

            return Http::response('', 500);
        });
    }

    private function mp3(): string
    {
        return 'ID3'."\x04\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x90\x64", 600);
    }

    private function body(TestResponse $response): string
    {
        ob_start();
        $response->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    private function assertPrivate(TestResponse $response): void
    {
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $this->assertStringNotContainsString('public', $cache);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNull($response->headers->get('Location'));
        $this->assertNull($response->headers->get('Content-Disposition'));
    }

    /**
     * @return list<string>
     */
    private function storageFiles(): array
    {
        return array_map(fn ($file) => $file->getPathname(), File::allFiles(storage_path('app')));
    }

    /**
     * Neither the key, the salt, a signature nor the one-time link may reach a log, a
     * response, the database or failed_jobs.
     *
     * @param  list<TestResponse>  $responses
     */
    private function assertSecretsNotLeaked(array $responses): void
    {
        $signs = array_map(fn (array $request) => basename(parse_url($request['url'], PHP_URL_PATH) ?: ''), array_filter($this->sent, fn (array $request) => str_starts_with($request['url'], self::API)));
        $texts = [
            ...$this->logs,
            DB::table('call_recordings')->get()->toJson(JSON_UNESCAPED_SLASHES),
            DB::table('failed_jobs')->get()->toJson(JSON_UNESCAPED_SLASHES),
            DB::table('audit_logs')->get()->toJson(JSON_UNESCAPED_SLASHES),
        ];

        foreach ($responses as $response) {
            $texts[] = json_encode($response->headers->all(), JSON_UNESCAPED_SLASHES).$response->baseResponse->getContent();
        }

        foreach ($texts as $text) {
            foreach (['test-vpbx-key', 'test-vpbx-salt', 'one-time-secret-link', 'mango-office.ru', ...$signs] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $text);
            }
        }
    }
}
