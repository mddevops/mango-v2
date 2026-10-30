<?php

namespace Tests\Feature\Mango;

use App\Enums\CallDirection;
use App\Enums\CallMatchState;
use App\Enums\MangoEventState;
use App\Jobs\ProcessMangoEvent;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\CallLeg;
use App\Models\Client;
use App\Models\IntegrationConnection;
use App\Models\IntegrationLine;
use App\Models\MangoEvent;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\User;
use App\Services\MangoCallService;
use App\Services\MangoEventProcessor;
use App\Services\OrderManager;
use App\Support\PermissionCatalog as P;
use Carbon\CarbonImmutable;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PDOException;
use RuntimeException;
use Throwable;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Mango\Concerns\BuildsMangoCalls;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

/**
 * Showrooms are in Moscow (UTC+3) with the 19:00 business day boundary; the test day
 * 2026-10-19 is a Monday.
 */
class MangoCallIntakeTest extends TestCase
{
    use BuildsMangoCalls;
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    private User $admin;

    private Showroom $showroom;

    private Source $source;

    private IntegrationConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
        $this->admin = $this->administrator(['name' => 'Админ']);
        $this->travelTo(CarbonImmutable::parse('2026-10-19 12:05', 'Europe/Moscow'));

        $this->showroom = $this->moscowShowroom();
        $this->source = $this->makeSource($this->showroom, ['name' => 'Сайт салона']);
        $this->connection = $this->mangoConnection($this->showroom);
        $this->bindLine($this->connection, $this->source, '+7 (495) 123-45-67');
    }

    public function test_signature_is_checked_over_the_raw_json_string_and_the_event_time_decides_the_business_day(): void
    {
        $operator = $this->mangoEmployee('Оператор', [$this->showroom]);
        $this->extension($this->connection, '123', $operator);
        $evening = 7 * 3600 + 30 * 60;
        $data = $this->summary('entry-raw', '123', $evening);
        // Unusual spacing and key order: only the string as received is signed.
        $json = "{ \"line_number\" : \"".self::LINE."\", \"entry_id\":\"entry-raw\",\n \"call_direction\":\"1\", \"from\":{\"number\":\"".self::CLIENT.'"}, "to":{"extension":123}, "create_time":'.$data['create_time'].', "talk_time":"'.$data['talk_time'].'", "end_time":'.$data['end_time'].', "entry_result":1 }';
        $reencoded = (string) json_encode(json_decode($json, true));

        $this->mangoPost($this->connection->webhook_key, 'summary', 'test-vpbx-key', $json, hash('sha256', 'test-vpbx-key'.$reencoded.'test-vpbx-salt'))->assertStatus(403);
        $this->assertSame(0, MangoEvent::query()->count());

        $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00', 'Europe/Moscow'));
        $this->mangoPost($this->connection->webhook_key, 'summary', 'test-vpbx-key', $json, hash('sha256', 'test-vpbx-key'.$json.'test-vpbx-salt'))
            ->assertOk()
            ->assertContent('');

        $event = MangoEvent::query()->sole();
        $this->assertSame($json, $event->payload);
        $this->assertSame(MangoEventState::Processed, $event->state);

        $order = Order::query()->with('channel')->sole();
        $this->assertSame('mango_vpbx', $order->origin->value);
        $this->assertSame('direct-call', $order->channel->code);
        $this->assertSame($this->source->id, $order->source_id);
        $this->assertSame(self::CLIENT, $order->phone);
        $this->assertSame($operator->id, $order->responsible_id);
        $this->assertNull($order->distribution_state);
        $this->assertTrue(OrderStatus::query()->whereKey($order->order_status_id)->value('is_initial'));
        $this->assertSame('2026-10-19 16:30:00', $order->event_at->utc()->format('Y-m-d H:i:s'), 'Unix time is read as is, without another +3 hours.');
        $this->assertSame('2026-10-20', $order->business_date, '19:30 Moscow is after the 19:00 boundary; the processing day does not matter.');

        $call = Call::query()->sole();
        $this->assertSame(30, $call->talk_seconds);
        $this->assertSame(Call::ANSWERED, $call->result);
        $this->assertSame($order->id, $call->order_id);

        $created = AuditLog::query()->where('event', 'order.created')->sole();
        $this->assertSame([AuditLog::ACTOR_SYSTEM, 'mango_vpbx'], [$created->actor_kind, $created->basis]);
    }

    public function test_wrong_signature_key_or_connection_is_rejected_and_nothing_is_saved(): void
    {
        $other = $this->mangoConnection($this->moscowShowroom(), 'Mango второй', 'other-key', 'other-salt');
        $data = $this->summary('entry-auth');
        $json = (string) json_encode($data);

        $this->mangoPost($this->connection->webhook_key, 'summary', 'test-vpbx-key', $json, hash('sha256', 'test-vpbx-key'.$json.'wrong-salt'))->assertStatus(403);
        $this->mangoPost($this->connection->webhook_key, 'summary', 'other-key', $json, hash('sha256', 'other-key'.$json.'test-vpbx-salt'))->assertStatus(403);
        $this->mangoEvent($other, 'summary', $data)->assertStatus(403);
        $this->mangoEvent($this->connection, 'summary', $data, 'other-key', 'other-salt')->assertStatus(403);
        $this->mangoPost('unknown-webhook-key', 'summary', 'test-vpbx-key', $json, hash('sha256', 'test-vpbx-key'.$json.'test-vpbx-salt'))->assertNotFound();
        $this->mangoEvent($this->connection, 'unknown', $data)->assertNotFound();
        $this->mangoPost($this->connection->webhook_key, 'summary', 'test-vpbx-key', '', '')->assertStatus(400);
        $this->call('POST', "/api/mango/{$this->connection->webhook_key}/events/summary", [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], '')->assertStatus(400);
        $this->mangoEvent($this->connection, 'summary', ['entry_id' => 'entry-auth', 'call_direction' => 5])->assertStatus(400);
        $this->mangoEvent($this->connection, 'recording', ['recording_id' => 'r1', 'entry_id' => 'entry-auth'])->assertStatus(400);
        $this->mangoEvent($this->connection, 'record/tagged', ['recording_id' => 'r1', 'entry_id' => 'entry-auth'])->assertOk();

        $this->connection->forceFill(['active' => false])->save();
        $this->mangoEvent($this->connection, 'summary', $data)->assertStatus(403);

        $this->assertSame(0, MangoEvent::query()->count());
        $this->assertSame(0, Call::query()->count());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_repeated_event_is_saved_and_applied_once(): void
    {
        $first = $this->routedCall('entry-repeat')[0];

        $this->mangoEvent($this->connection, 'call', $first)->assertOk();
        $this->mangoEvent($this->connection, 'call', $first)->assertOk();
        $this->mangoEvent($this->connection, 'call', array_reverse($first, true))->assertOk();
        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-repeat', null))->assertOk();
        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-repeat', null, 0, ['disconnect_reason' => 1110]))->assertOk();

        $this->assertSame(2, MangoEvent::query()->count());
        $this->assertSame(1, Call::query()->count());
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'order.created')->count());
        $this->assertSame(1100, Call::query()->sole()->disconnect_reason);
    }

    public function test_seq_orders_events_within_one_leg_only(): void
    {
        [$ivr, $ivrEnd, $ring, $answer, $hangup] = $this->routedCall('entry-seq');

        foreach ([$hangup, $ivrEnd, $answer, $ring, $ivr] as $event) {
            $this->mangoEvent($this->connection, 'call', $event)->assertOk();
        }

        $leg = CallLeg::query()->where('mango_call_id', 'entry-seq:abonent')->sole();
        $this->assertSame(3, $leg->last_seq);
        $this->assertSame('Disconnected', $leg->state, 'Older seq of the same leg does not roll the state back.');
        $this->assertSame('123', $leg->answered_extension, 'The connection fact of an older event still counts.');
        $this->assertSame($this->at(12), $leg->connected_at->getTimestamp());

        $ivrLeg = CallLeg::query()->where('mango_call_id', 'entry-seq:ivr')->sole();
        $this->assertSame(2, $ivrLeg->last_seq, 'seq 1 of another leg is not compared with seq 3 of the first.');
        $this->assertSame($this->at(), $ivrLeg->appeared_at->getTimestamp());

        $call = Call::query()->sole();
        $this->assertSame(CallDirection::Inbound, $call->direction);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_summary_before_realtime_creates_one_order_and_late_realtime_only_adds_legs(): void
    {
        $operator = $this->mangoEmployee('Оператор', [$this->showroom]);
        $this->extension($this->connection, '123', $operator);

        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-late'))->assertOk();
        $order = Order::query()->sole();
        $this->assertSame($operator->id, $order->responsible_id);
        $version = $order->lock_version;

        foreach ($this->routedCall('entry-late') as $event) {
            $this->mangoEvent($this->connection, 'call', $event)->assertOk();
        }

        $call = Call::query()->with('legs')->sole();
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'order.created')->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'order.responsible_changed')->count());
        $this->assertSame($version, $order->fresh()->lock_version);
        $this->assertCount(2, $call->legs);
        $this->assertSame([Call::ANSWERED, 30, $this->at(42)], [$call->result, $call->talk_seconds, $call->ended_at->getTimestamp()]);
        $this->assertSame($operator->id, $call->answered_by_id);
        $this->assertSame([$operator->id], $call->legs->pluck('answered_by_id')->filter()->values()->all());
    }

    public function test_transfer_keeps_the_first_answerer_responsible_and_every_answerer_on_the_legs(): void
    {
        $first = $this->mangoEmployee('Первый', [$this->showroom]);
        $second = $this->mangoEmployee('Второй', [$this->showroom]);
        $this->extension($this->connection, '123', $first);
        $this->extension($this->connection, '321', $second);
        $entry = 'entry-transfer';

        foreach ($this->transferEvents($entry, self::LINE) as $event) {
            $this->mangoEvent($this->connection, 'call', $event)->assertOk();
        }

        $this->mangoEvent($this->connection, 'summary', $this->summary($entry, '321', 0, ['talk_time' => $this->at(9), 'end_time' => $this->at(69)]))->assertOk();

        $order = Order::query()->sole();
        $call = Call::query()->with('legs')->sole();
        $this->assertSame($first->id, $order->responsible_id, 'The transfer to the second employee does not move the order.');
        $this->assertSame($first->id, $call->answered_by_id);
        $this->assertSame(
            ["{$entry}:100:1" => null, "{$entry}:200:514" => $first->id, "{$entry}:202:515" => $second->id],
            $call->legs->pluck('answered_by_id', 'mango_call_id')->all(),
        );
        $this->assertSame("{$entry}:200:514", $call->legs->firstWhere('mango_call_id', "{$entry}:202:515")->taken_from_call_id);
        $this->assertSame(60, $call->talk_seconds);
        $this->assertSame([[null, 'Первый']], AuditLog::query()->where('event', 'order.responsible_changed')->pluck('changes')->pluck('responsible')->all(), 'Created at the first event, assigned once at the answer.');
    }

    public function test_out_of_order_transfer_takes_the_earliest_known_answerer_and_late_events_do_not_revise_it(): void
    {
        $first = $this->mangoEmployee('Первый', [$this->showroom]);
        $second = $this->mangoEmployee('Второй', [$this->showroom]);
        $this->extension($this->connection, '123', $first);
        $this->extension($this->connection, '321', $second);

        // The line is not bound yet: all facts of the call arrive before it gets an order.
        $events = $this->transferEvents('entry-early', '74950000003');

        foreach (['replaced', 'end', 'connected', 'ivr', 'appeared'] as $name) {
            $this->mangoEvent($this->connection, 'call', $events[$name])->assertOk();
        }

        $this->assertSame(0, Order::query()->count());
        $this->bindLine($this->connection, $this->source, '74950000003');
        $this->artisan('mango-calls:rematch', ['--call' => Call::query()->sole()->id])->assertSuccessful();

        $this->assertSame($first->id, Order::query()->sole()->responsible_id, 'Of the known connections the earliest one decides.');

        // The order is assigned when the later answerer is the only one known so far: the
        // late connection of the first employee does not revise it.
        $late = $this->transferEvents('entry-late-first', self::LINE, 7, '79001112233');

        foreach (['ivr', 'replaced', 'end', 'connected', 'oldEnd'] as $name) {
            $this->mangoEvent($this->connection, 'call', $late[$name])->assertOk();
        }

        $order = Order::query()->where('phone', '79001112233')->sole();
        $this->assertSame($second->id, $order->responsible_id);
        $this->assertSame($first->id, Call::query()->where('entry_id', 'entry-late-first')->value('answered_by_id'), 'The call itself names the earliest answerer.');
        $this->assertSame([[null, 'Второй']], AuditLog::query()->where('event', 'order.responsible_changed')->pluck('changes')->pluck('responsible')->all());
    }

    /**
     * Consultative transfer of «Примеры поведения»: client ↔ 123, 123 consults 321, then
     * 321 replaces 123 in the conversation with the client.
     *
     * @return array<string, array<string, mixed>>
     */
    private function transferEvents(string $entry, string $lineNumber, int $offset = 0, string $clientNumber = self::CLIENT): array
    {
        $client = ['number' => $clientNumber];
        $line = ['number' => $lineNumber, 'line_number' => $lineNumber];
        $to123 = ['extension' => '123', 'number' => '12345678'];
        $to321 = ['extension' => '321', 'number' => '87654321'];
        $at = fn (int $seconds) => $this->at($offset * 100 + $seconds);

        return [
            'ivr' => $this->leg($entry, "{$entry}:100:1", 1, 'Appeared', $at(0), $client, $line, ['location' => 'ivr']),
            'ivrEnd' => $this->leg($entry, "{$entry}:100:1", 2, 'Disconnected', $at(1), $client, $line, ['location' => 'ivr']),
            'appeared' => $this->leg($entry, "{$entry}:200:514", 1, 'Appeared', $at(2), [...$client, 'taken_from_call_id' => "{$entry}:100:1"], $to123),
            'connected' => $this->leg($entry, "{$entry}:200:514", 2, 'Connected', $at(9), [...$client, 'taken_from_call_id' => "{$entry}:100:1"], $to123),
            'hold' => $this->leg($entry, "{$entry}:200:514", 3, 'OnHold', $at(19), [...$client, 'taken_from_call_id' => "{$entry}:100:1"], $to123),
            'consult' => $this->leg($entry, "{$entry}:202:515", 1, 'Appeared', $at(29), ['extension' => '123', 'number' => '12345678', 'taken_from_call_id' => "{$entry}:200:514"], $to321),
            'consulting' => $this->leg($entry, "{$entry}:202:515", 2, 'Connected', $at(29), ['extension' => '123', 'number' => '12345678', 'taken_from_call_id' => "{$entry}:200:514"], $to321),
            'replaced' => $this->leg($entry, "{$entry}:202:515", 3, 'Connected', $at(39), [...$client, 'taken_from_call_id' => "{$entry}:200:514"], $to321),
            'oldEnd' => $this->leg($entry, "{$entry}:200:514", 4, 'Disconnected', $at(39), [...$client, 'taken_from_call_id' => "{$entry}:100:1"], $to123, ['disconnect_reason' => 1120]),
            'end' => $this->leg($entry, "{$entry}:202:515", 4, 'Disconnected', $at(69), [...$client, 'taken_from_call_id' => "{$entry}:200:514"], $to321, ['disconnect_reason' => 1110]),
        ];
    }

    public function test_equal_entry_id_of_two_connections_are_two_calls(): void
    {
        $otherShowroom = $this->moscowShowroom();
        $otherSource = $this->makeSource($otherShowroom);
        $other = $this->mangoConnection($otherShowroom, 'Mango второй', 'other-key', 'other-salt');
        $this->bindLine($other, $otherSource, self::LINE);

        $this->mangoEvent($this->connection, 'summary', $this->summary('same-entry', null))->assertOk();
        $this->mangoEvent($other, 'summary', $this->summary('same-entry', null), 'other-key', 'other-salt')->assertOk();

        $this->assertSame(2, MangoEvent::query()->count());
        $this->assertSame(2, Call::query()->count());
        $this->assertEqualsCanonicalizing([$this->showroom->id, $otherShowroom->id], Order::query()->pluck('showroom_id')->all());
        $this->assertSame(1, Client::query()->count());
    }

    public function test_existing_order_is_found_by_main_or_additional_number_and_keeps_its_state(): void
    {
        $owner = $this->mangoEmployee('Владелец', [$this->showroom]);
        $otherSource = $this->makeSource($this->showroom, ['name' => 'Другой источник']);
        $this->bindLine($this->connection, $otherSource, '74950000002');
        $older = $this->manualOrder(['79001112233'], $owner);
        $order = $this->manualOrder(['79990001122', self::CLIENT], $owner);
        $status = OrderStatus::query()->where('is_initial', false)->where('active', true)->whereNull('purpose')
            ->where('requires_callback_at', false)->where('requires_arrival_at', false)->whereNull('required_reason')->firstOrFail();
        Order::query()->whereKey($order->id)->update(['order_status_id' => $status->id, 'updated_at' => CarbonImmutable::parse('2026-10-18 10:00', 'UTC')]);
        $before = $order->fresh();

        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-extra', null, 0, ['line_number' => '74950000002']))->assertOk();

        $call = Call::query()->sole();
        $this->assertSame($order->id, $call->order_id, 'The additional number of the order matches.');
        $this->assertFalse($call->order_created);
        $this->assertSame($otherSource->id, $call->source_id, 'The call keeps its own line and source.');
        $after = $order->fresh();
        $this->assertSame(
            [$before->order_status_id, $before->source_id, $before->responsible_id, $before->business_date, $before->repeat_number, $before->lock_version, $before->updated_at->toISOString()],
            [$after->order_status_id, $after->source_id, $after->responsible_id, $after->business_date, $after->repeat_number, $after->lock_version, $after->updated_at->toISOString()],
        );
        $this->assertSame(2, Order::query()->count());

        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-main', null, 0, ['from' => ['number' => '8 900 111-22-33']]))->assertOk();
        $this->assertSame($older->id, Call::query()->where('entry_id', 'entry-main')->value('order_id'), 'The main number matches.');
        $this->assertSame(2, Order::query()->count());
    }

    public function test_existing_responsible_stays_when_another_employee_answers(): void
    {
        $owner = $this->mangoEmployee('Владелец', [$this->showroom]);
        $answerer = $this->mangoEmployee('Ответивший', [$this->showroom], [P::ORDERS_VIEW]);
        $this->extension($this->connection, '123', $answerer);
        $order = $this->manualOrder([self::CLIENT], $owner);
        $before = $order->fresh();

        foreach ($this->routedCall('entry-answer') as $event) {
            $this->mangoEvent($this->connection, 'call', $event)->assertOk();
        }

        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-answer'))->assertOk();

        $after = $order->fresh();
        $this->assertSame($owner->id, $after->responsible_id);
        $this->assertSame([$before->lock_version, $before->updated_at->toISOString()], [$after->lock_version, $after->updated_at->toISOString()]);
        $this->assertSame(0, AuditLog::query()->where('event', 'order.responsible_changed')->count());

        $call = Call::query()->sole();
        $this->assertSame([$answerer->id, null], [$call->answered_by_id, $call->assigned_user_id], 'The call still names who answered.');
        $this->assertNotNull($call->assignment_decided_at);
    }

    public function test_existing_unassigned_order_gets_the_answerer_and_an_unsuitable_one_is_not_assigned(): void
    {
        $answerer = $this->mangoEmployee('Ответивший', [$this->showroom], [P::ORDERS_VIEW]);
        $foreign = $this->mangoEmployee('Чужой салон', [$this->moscowShowroom()]);
        $this->extension($this->connection, '123', $answerer);
        $this->extension($this->connection, '555', $foreign);
        $order = $this->manualOrder([self::CLIENT], null);
        $version = $order->fresh()->lock_version;

        foreach ($this->routedCall('entry-foreign', '555') as $event) {
            $this->mangoEvent($this->connection, 'call', $event)->assertOk();
        }

        $this->assertNull($order->fresh()->responsible_id, 'An employee without access to the showroom is not assigned.');
        $this->assertTrue(Call::query()->where('entry_id', 'entry-foreign')->value('needs_review'));

        foreach ($this->routedCall('entry-answer', '123', 600) as $event) {
            $this->mangoEvent($this->connection, 'call', $event)->assertOk();
        }

        $order->refresh();
        $this->assertSame($answerer->id, $order->responsible_id);
        $this->assertSame($version + 1, $order->lock_version);
        $change = AuditLog::query()->where('event', 'order.responsible_changed')->sole();
        $this->assertSame('mango_vpbx', $change->basis);
        $this->assertSame([null, 'Ответивший'], $change->changes['responsible']);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_missed_call_creates_an_unassigned_order_or_keeps_the_responsible(): void
    {
        foreach ($this->routedCall('entry-missed', '123', 0, false) as $event) {
            $this->mangoEvent($this->connection, 'call', $event)->assertOk();
        }

        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-missed', null))->assertOk();

        $order = Order::query()->sole();
        $this->assertNull($order->responsible_id);
        $this->assertNull($order->distribution_state, 'Direct calls are not distributed.');
        $call = Call::query()->sole();
        $this->assertSame([Call::MISSED, null], [$call->result, $call->talk_seconds]);
        $this->assertFalse($call->needs_review);

        $owner = $this->mangoEmployee('Владелец', [$this->showroom]);
        $order->forceFill(['responsible_id' => $owner->id])->save();
        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-missed-2', null, 600))->assertOk();

        $this->assertSame($owner->id, $order->fresh()->responsible_id);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_unknown_line_extension_or_hidden_number_keeps_the_call_without_guessing(): void
    {
        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-line', null, 0, ['line_number' => '74959999999']))->assertOk();
        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-hidden', null, 0, ['from' => []]))->assertOk();
        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-sip', null, 0, ['from' => ['number' => 'sip:user@xyz.mangosip.ru']]))->assertOk();

        $calls = Call::query()->get()->keyBy('entry_id');
        $this->assertSame(CallMatchState::UnknownLine, $calls['entry-line']->match_state);
        $this->assertNull($calls['entry-line']->showroom_id);
        $this->assertSame(CallMatchState::NoClientPhone, $calls['entry-hidden']->match_state);
        $this->assertSame(CallMatchState::NoClientPhone, $calls['entry-sip']->match_state);
        $this->assertTrue($calls->every(fn (Call $call) => $call->needs_review && $call->order_id === null));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Client::query()->count());

        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-ext', '777'))->assertOk();
        $call = Call::query()->where('entry_id', 'entry-ext')->sole();
        $this->assertNotNull($call->order_id);
        $this->assertNull(Order::query()->sole()->responsible_id, 'Nobody random is assigned for an unknown extension.');
        $this->assertSame(['777', true], [$call->answered_extension, $call->needs_review]);

        $other = $this->makeSource($this->showroom, ['name' => 'Новая линия']);
        $this->bindLine($this->connection, $other, '74959999999');
        $this->actingAs($this->admin)
            ->postJson(route('connections.calls.rematch', [$this->connection, $calls['entry-line']]))
            ->assertOk()
            ->assertJsonPath('match_state', 'linked');

        $rematched = $calls['entry-line']->fresh();
        $this->assertSame([$other->id, $call->order_id, false], [$rematched->source_id, $rematched->order_id, $rematched->needs_review], 'The client already has an order in the showroom.');
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'mango_call.rematched')->count());
    }

    public function test_technical_callback_and_outgoing_calls_do_not_create_incoming_orders(): void
    {
        $entry = 'entry-callback';
        // «Инициирование исходящего вызова»: the command first calls the employee.
        $this->mangoEvent($this->connection, 'call', $this->leg($entry, '100:500:251', 1, 'Appeared', $this->at(), ['extension' => '5555', 'number' => self::CLIENT], ['extension' => '1234', 'number' => '12345678'], ['command_id' => 'cmd.2']))->assertOk();
        $this->mangoEvent($this->connection, 'call', $this->leg($entry, '100:500:258', 1, 'Appeared', $this->at(5), ['extension' => '1234', 'number' => '12345678', 'taken_from_call_id' => '100:500:251'], ['extension' => '5555', 'number' => self::CLIENT], ['command_id' => 'cmd.2']))->assertOk();
        $this->mangoEvent($this->connection, 'call', $this->leg('entry-task', 'task:1', 1, 'Appeared', $this->at(), ['number' => self::CLIENT], ['number' => self::LINE, 'line_number' => self::LINE], ['task_id' => '77']))->assertOk();

        $this->assertSame(CallMatchState::Technical, Call::query()->where('entry_id', $entry)->value('match_state'));
        $this->assertSame(CallMatchState::Technical, Call::query()->where('entry_id', 'entry-task')->value('match_state'));
        $this->assertSame(0, Order::query()->count());

        $outgoing = ['call_direction' => 2, 'from' => ['extension' => '123', 'number' => 'sip:user1@xyz.mangosip.ru'], 'to' => ['number' => self::CLIENT]];
        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-out', null, 0, $outgoing))->assertOk();
        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-internal', null, 0, ['call_direction' => 0]))->assertOk();
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(CallMatchState::NoOrder, Call::query()->where('entry_id', 'entry-out')->value('match_state'));
        $this->assertSame(CallMatchState::Internal, Call::query()->where('entry_id', 'entry-internal')->value('match_state'));

        $order = $this->manualOrder([self::CLIENT], $this->mangoEmployee('Владелец', [$this->showroom]));
        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-out-2', null, 600, $outgoing))->assertOk();
        $this->assertSame($order->id, Call::query()->where('entry_id', 'entry-out-2')->value('order_id'), 'An outgoing call joins the existing order.');
        $this->assertSame(1, Order::query()->count());
    }

    public function test_event_saved_without_a_queued_job_is_recovered_and_failures_stay_for_a_retry(): void
    {
        config(['queue.default' => 'broken', 'queue.connections.broken' => ['driver' => 'missing-driver']]);

        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-queue', null))->assertOk();

        $event = MangoEvent::query()->sole();
        $this->assertSame([MangoEventState::Accepted, 'not_queued'], [$event->state, $event->error_code]);
        $this->assertSame(0, Call::query()->count());

        $this->artisan('mango-events:process', ['--older-than' => 0])->assertSuccessful();
        $this->assertSame([MangoEventState::Processed, null], [$event->fresh()->state, $event->fresh()->error_code]);
        $this->assertSame(1, Order::query()->count());

        config(['queue.default' => 'sync']);
        Queue::fake();
        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-fail', null, 600))->assertOk();
        Queue::assertPushed(ProcessMangoEvent::class);
        $failing = MangoEvent::query()->where('entry_id', 'entry-fail')->sole();
        $this->failingCalls();

        for ($attempt = 1; $attempt <= MangoEventProcessor::MAX_ATTEMPTS; $attempt++) {
            try {
                app(MangoEventProcessor::class)->process($failing->id);
            } catch (RuntimeException) {
            }
        }

        $this->assertSame([MangoEventState::Failed, 'processing_error'], [$failing->fresh()->state, $failing->fresh()->error_code]);

        $this->app->offsetUnset(MangoCallService::class);
        $this->app->forgetInstance(MangoEventProcessor::class);
        $this->artisan('mango-events:retry-failed', ['--event' => $failing->id])->assertSuccessful();

        $this->assertSame(MangoEventState::Processed, $failing->fresh()->state);
        $this->assertSame(1, AuditLog::query()->where('event', 'mango_event.retried')->count());
        $this->assertSame(1, Order::query()->count(), 'The second call of the client joins the first order.');
    }

    public function test_database_error_reveals_no_phone_payload_or_key_and_the_event_can_be_retried(): void
    {
        config(['queue.default' => 'database']);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged) {
            $logged[] = $log->message.' '.json_encode($log->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR).' '.($log->context['exception'] ?? '');
        });

        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-db-error', null))->assertOk();
        $event = MangoEvent::query()->sole();
        $secrets = [self::CLIENT, 'test-vpbx-key', 'test-vpbx-salt', $event->payload, 'entry-db-error'];
        $this->failingCalls(new QueryException(
            'mysql',
            'insert into `calls` (`client_phone`, `payload`, `key`) values (?, ?, ?)',
            [self::CLIENT, $event->payload, 'test-vpbx-key'],
            tap(new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \''.self::CLIENT.'\''), fn (PDOException $pdo) => $pdo->errorInfo = ['23000', 1062, 'Duplicate entry \''.self::CLIENT.'\'']),
        ));

        // The queue gives up on the job while the event still has attempts: the failure
        // reaches failed_jobs.
        DB::table('jobs')->update(['attempts' => MangoEventProcessor::MAX_ATTEMPTS - 1]);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => ProcessMangoEvent::QUEUE]);
        $output = Artisan::output();

        $failed = DB::table('failed_jobs')->sole();
        $stored = MangoEvent::query()->find($event->id);
        $this->assertSame([MangoEventState::Failed, 'processing_error'], [$stored->state, $stored->error_code]);
        $this->assertStringContainsString('23000', $failed->exception);
        $this->assertStringContainsString(QueryException::class, $failed->exception);
        $this->assertNotEmpty($logged);

        foreach ([$output, $failed->exception, $failed->payload, (string) $stored->error_code, ...$logged] as $text) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $text);
            }
        }

        $this->app->offsetUnset(MangoCallService::class);
        $this->app->forgetInstance(MangoEventProcessor::class);
        config(['queue.default' => 'sync']);
        $this->artisan('mango-events:retry-failed', ['--event' => $event->id])->assertSuccessful();

        $this->assertSame(MangoEventState::Processed, $event->fresh()->state);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_repeated_processing_does_not_undo_a_manual_change(): void
    {
        Queue::fake();
        $operator = $this->mangoEmployee('Оператор', [$this->showroom]);
        $manual = $this->mangoEmployee('Назначен вручную', [$this->showroom]);
        $this->extension($this->connection, '123', $operator);
        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-manual'))->assertOk();
        $event = MangoEvent::query()->sole();
        app(MangoEventProcessor::class)->process($event->id);

        $order = Order::query()->sole();
        $this->assertSame($operator->id, $order->responsible_id);
        Order::query()->whereKey($order->id)->update(['responsible_id' => $manual->id]);
        $this->repeatEverything($event);

        $this->assertSame($manual->id, $order->fresh()->responsible_id);

        // Removed by hand: the already processed call does not take the order again.
        Order::query()->whereKey($order->id)->update(['responsible_id' => null]);
        $this->repeatEverything($event);

        foreach ($this->routedCall('entry-manual') as $late) {
            $this->mangoEvent($this->connection, 'call', $late)->assertOk();
        }

        foreach (MangoEvent::query()->where('state', MangoEventState::Accepted)->pluck('id') as $id) {
            app(MangoEventProcessor::class)->process($id);
        }

        $this->assertNull($order->fresh()->responsible_id);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'order.created')->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'order.responsible_changed')->count());
    }

    public function test_another_call_does_not_replace_the_responsible_set_by_the_first_one(): void
    {
        $older = $this->mangoEmployee('Старый звонок', [$this->showroom]);
        $newer = $this->mangoEmployee('Новый звонок', [$this->showroom]);
        $this->extension($this->connection, '111', $older);
        $this->extension($this->connection, '222', $newer);

        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-new', '222', 900))->assertOk();
        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-old', '111', 0))->assertOk();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame($newer->id, Order::query()->sole()->responsible_id);
        $this->assertSame($older->id, Call::query()->where('entry_id', 'entry-old')->value('answered_by_id'), 'The call itself still names who answered it.');
        $this->assertSame(0, AuditLog::query()->where('event', 'order.responsible_changed')->count());
    }

    /**
     * A redelivered webhook, a repeated job, a reprocessed event and a rematch of the call.
     */
    private function repeatEverything(MangoEvent $event): void
    {
        $this->mangoEvent($this->connection, 'summary', json_decode($event->payload, true))->assertOk();
        (new ProcessMangoEvent($event->id))->handle(app(MangoEventProcessor::class));
        MangoEvent::query()->whereKey($event->id)->update(['state' => MangoEventState::Accepted->value]);
        app(MangoEventProcessor::class)->process($event->id);
        $this->artisan('mango-calls:rematch', ['--call' => Call::query()->sole()->id])->assertSuccessful();
    }

    public function test_calls_are_shown_with_history_rights_and_order_scope_only(): void
    {
        $operator = $this->mangoEmployee('Оператор', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_HISTORY]);
        $this->extension($this->connection, '123', $operator);

        foreach ($this->routedCall('entry-card') as $event) {
            $this->mangoEvent($this->connection, 'call', $event)->assertOk();
        }

        $this->mangoEvent($this->connection, 'summary', $this->summary('entry-card'))->assertOk();
        $order = Order::query()->sole();

        $this->actingAs($operator)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page->missing('calls')
                ->reloadOnly('calls', fn (Assert $reload) => $reload
                    ->has('calls.data', 1)
                    ->where('calls.data.0.direction', 'Входящий')
                    ->where('calls.data.0.answered', 'Оператор')
                    ->where('calls.data.0.talk_seconds', 30)
                    ->where('calls.data.0.missed', false)
                    ->where('calls.data.0.source', $this->source->name)
                    ->has('calls.data.0.legs', 2)
                    ->missing('calls.data.0.entry_id')));

        $withoutHistory = $this->mangoEmployee('Без истории', [$this->showroom], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL]);
        $this->actingAs($withoutHistory)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page->where('can.history', false)->missing('calls'));

        $foreign = $this->mangoEmployee('Чужой салон', [$this->moscowShowroom()], [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL, P::ORDERS_HISTORY]);
        $this->actingAs($foreign)->get(route('orders.show', $order->id))->assertNotFound();

        $partial = $this->mangoEmployee('Только подключения', [$this->showroom], [P::CONNECTIONS_MANAGE, P::CONNECTIONS_SECRETS]);
        $call = Call::query()->sole();
        $this->actingAs($partial)->getJson(route('connections.mango.show', $this->connection))->assertForbidden();
        $this->actingAs($partial)->postJson(route('connections.calls.rematch', [$this->connection, $call]))->assertForbidden();

        $other = $this->mangoConnection($this->moscowShowroom(), 'Mango второй');
        $this->actingAs($this->admin)->postJson(route('connections.calls.rematch', [$other, $call]))->assertNotFound();
        $this->actingAs($this->admin)->getJson(route('connections.mango.show', $this->connection))
            ->assertOk()
            ->assertJsonPath('webhook_url', url('api/mango/'.$this->connection->webhook_key))
            ->assertJsonPath('extensions.0.extension', '123')
            ->assertJsonPath('review_count', 0)
            ->assertDontSee('test-vpbx-key')
            ->assertDontSee('test-vpbx-salt');
    }

    public function test_extensions_are_unique_within_a_connection_only(): void
    {
        $first = $this->mangoEmployee('Первый', [$this->showroom]);
        $second = $this->mangoEmployee('Второй', [$this->showroom]);
        $other = $this->mangoConnection($this->moscowShowroom(), 'Mango второй');

        $this->actingAs($this->admin)->postJson(route('connections.extensions.store', $this->connection), ['extension' => '101', 'user_id' => $first->id])->assertCreated();
        $this->actingAs($this->admin)->postJson(route('connections.extensions.store', $this->connection), ['extension' => '101', 'user_id' => $second->id])
            ->assertStatus(422)->assertJsonValidationErrors('extension');
        $this->actingAs($this->admin)->postJson(route('connections.extensions.store', $other), ['extension' => '101', 'user_id' => $second->id])->assertCreated();

        $this->assertSame(['integration_connection.extension_added', 'integration_connection.extension_added'], AuditLog::query()->where('subject_type', 'integration_connection')->pluck('event')->all());
    }

    private function moscowShowroom(): Showroom
    {
        return Showroom::factory()->create([
            'timezone' => 'Europe/Moscow',
            'business_day_boundary_enabled' => true,
            'business_day_boundary' => '19:00',
        ]);
    }

    /**
     * @param  list<string>  $phones
     */
    private function manualOrder(array $phones, ?User $responsible): Order
    {
        $channel = $this->source->channels()->where('code', 'callback-request')->value('contact_channels.id');

        return app(OrderManager::class)->create($this->admin, [
            'showroom_id' => $this->showroom->id,
            'source_id' => $this->source->id,
            'contact_channel_id' => $channel,
            'contact_name' => 'Клиент',
            'phones' => $phones,
            'phone_names' => [],
            'car_interest' => null,
            'responsible_id' => $responsible?->id,
        ]);
    }

    private function failingCalls(?Throwable $error = null): void
    {
        $this->app->instance(MangoCallService::class, new class($error ?? new RuntimeException('Calls are down.')) extends MangoCallService
        {
            public function __construct(private readonly Throwable $error) {}

            public function apply(int $eventId): void
            {
                throw $this->error;
            }
        });
        $this->app->forgetInstance(MangoEventProcessor::class);
    }
}
