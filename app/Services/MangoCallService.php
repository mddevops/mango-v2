<?php

namespace App\Services;

use App\Enums\CallDirection;
use App\Enums\CallMatchState;
use App\Enums\MangoEventState;
use App\Exceptions\MangoEventFailure;
use App\Models\Call;
use App\Models\CallLeg;
use App\Models\CallRecording;
use App\Models\ContactChannel;
use App\Models\IntegrationConnection;
use App\Models\IntegrationLine;
use App\Models\MangoEvent;
use App\Models\Source;
use App\Models\TelephonyExtension;
use App\Models\User;
use App\Support\MangoEventContract;
use App\Support\Phone;
use App\Support\PermissionCatalog as P;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies Mango events to calls. One transaction per event, and every event of a call
 * takes the same lock — the `calls` row, created by an insert that the unique index
 * (connection, entry_id) turns into a no-op for the second of two racing events.
 *
 * Lock order: event row → call row → client phones → client → order. No external request
 * is made inside the transaction.
 *
 * Nothing here depends on the delivery order: each leg keeps its highest seq, the summary
 * is applied once, the connection moments come from Mango's timestamps, and an order is
 * created only while the call has none.
 *
 * @phpstan-import-type CallEvent from MangoEventContract
 * @phpstan-import-type SummaryEvent from MangoEventContract
 */
class MangoCallService
{
    public const BASIS = 'mango_vpbx';

    /** A concurrent call of the same client won one of these: the transaction is repeated. */
    private const RETRY_INDEXES = ['client_phones_phone_unique', 'orders_client_showroom_repeat_unique'];

    public function __construct(
        private readonly OrderManager $orders,
        private readonly AuditLogger $audit,
        private readonly RealtimePublisher $realtime,
        private readonly CallCards $cards,
    ) {}

    public function apply(int $eventId): void
    {
        $this->audit->asSystem(self::BASIS, fn () => $this->retrying(fn () => $this->applyEvent($eventId)));
    }

    /**
     * Matches the call again after the settings were fixed: line, source, showroom,
     * order and answerer. A call that already has its order keeps it.
     */
    public function rematch(Call $call, ?User $actor): Call
    {
        try {
            $result = $this->audit->asSystem(self::BASIS, fn () => $this->retrying(function () use ($call) {
                $locked = Call::query()->lockForUpdate()->findOrFail($call->id);
                $this->settle($locked, $locked->connection()->firstOrFail());
                $this->realtime->callChanged($locked->id);

                return $locked;
            }));
        } catch (Throwable $exception) {
            $failure = MangoEventFailure::from($exception);
            Log::warning('Mango call rematch failed.', ['call_id' => $call->id, 'connection_id' => $call->integration_connection_id, 'error_code' => $failure->errorCode, ...$failure->diagnostics]);

            throw $failure;
        }

        $connection = $result->connection()->first(['id', 'name']);

        $this->audit->as($actor, fn () => $this->audit->record('mango_call.rematched', 'integration_connection', $result->integration_connection_id, (string) $connection?->name, 'Повторное сопоставление звонка Mango', [
            'call_id' => $result->id,
            'match_state' => $result->match_state->label(),
        ], $result->showroom_id === null ? [] : [$result->showroom_id]));

        return $result;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function retrying(callable $work): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($work, 3);
            } catch (UniqueConstraintViolationException $exception) {
                $retry = collect(self::RETRY_INDEXES)->contains(fn (string $index) => str_contains($exception->getMessage(), $index));

                if (! $retry || $attempt >= 3) {
                    throw $exception;
                }
            }
        }
    }

    private function applyEvent(int $eventId): void
    {
        /** @var MangoEvent|null $event */
        $event = MangoEvent::query()->lockForUpdate()->find($eventId);

        if ($event === null || $event->state !== MangoEventState::Accepted) {
            return;
        }

        $data = MangoEventContract::parse($event->kind, $event->payload)
            ?? throw new MangoEventFailure('invalid_payload', false);
        $connection = IntegrationConnection::query()->findOrFail($event->integration_connection_id);
        $now = CarbonImmutable::now();

        DB::table('calls')->insertOrIgnore([
            'integration_connection_id' => $connection->id,
            'entry_id' => $data['entry_id'],
            'match_state' => CallMatchState::Pending->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var Call $call */
        $call = Call::query()
            ->where('integration_connection_id', $connection->id)
            ->where('entry_id', $data['entry_id'])
            ->lockForUpdate()
            ->firstOrFail();

        // Recordings that came before their call (a recording event holds the call lock or
        // its index gap, so none is committed unseen in between).
        CallRecording::query()
            ->where('integration_connection_id', $connection->id)
            ->where('entry_id', $data['entry_id'])
            ->whereNull('call_id')
            ->update(['call_id' => $call->id]);

        if ($data['kind'] === MangoEvent::CALL) {
            $this->applyRealtime($call, $data);
        } else {
            $this->applySummary($call, $data);
        }

        $moment = MangoEventContract::occurredAt($data);

        if ($moment !== null && ($call->last_event_at === null || $moment->greaterThan($call->last_event_at))) {
            $call->last_event_at = $moment;
        }

        $this->settle($call, $connection);
        $this->announce($call);
    }

    /**
     * Realtime signals of the event, sent after commit: the call changed, and the
     * incoming call card of every employee the call is addressed to (by the extensions
     * in its legs). Whether he may see the card is decided when the signal is sent.
     */
    private function announce(Call $call): void
    {
        $this->realtime->callChanged($call->id);

        if ($call->direction !== CallDirection::Inbound || $call->technical || $call->order_id === null) {
            return;
        }

        foreach ($this->cards->targets($call) as $userId) {
            $this->realtime->callCard($call->id, $userId);
        }
    }

    /**
     * @param  CallEvent  $data
     */
    private function applyRealtime(Call $call, array $data): void
    {
        // Locking reads: rows committed by an earlier event of this call must be seen even
        // when this transaction's snapshot is older than that commit.
        $leg = CallLeg::query()->where('call_id', $call->id)->where('mango_call_id', $data['call_id'])->lockForUpdate()->first()
            ?? new CallLeg(['call_id' => $call->id, 'mango_call_id' => $data['call_id']]);
        $moment = MangoEventContract::moment($data['timestamp']);

        $leg->taken_from_call_id ??= $data['from']['taken_from_call_id'];
        $leg->technical = $leg->technical || $data['technical'];

        if ($moment !== null && ($leg->appeared_at === null || $moment->lessThan($leg->appeared_at))) {
            $leg->appeared_at = $moment;
        }

        if ($data['call_state'] === 'Disconnected' && $moment !== null && ($leg->ended_at === null || $moment->greaterThan($leg->ended_at))) {
            $leg->ended_at = $moment;
        }

        $employee = $this->clientConnection($data);

        if ($employee !== null && $moment !== null && ($leg->connected_at === null || $moment->lessThan($leg->connected_at))) {
            $leg->connected_at = $moment;
            $leg->answered_extension = $employee;
        }

        // An event with a lower seq of this leg is older: its facts above still count,
        // its state does not replace the newer one.
        if ($data['seq'] > $leg->last_seq) {
            $leg->fill([
                'last_seq' => $data['seq'],
                'state' => $data['call_state'],
                'location' => $data['location'],
                'from_extension' => $data['from']['extension'],
                'from_number' => $data['from']['number'],
                'to_extension' => $data['to']['extension'],
                'to_number' => $data['to']['number'],
                'line_number' => $data['to']['line_number'] ?? $leg->line_number,
                'disconnect_reason' => $data['disconnect_reason'] ?? $leg->disconnect_reason,
            ]);
        }

        $leg->save();

        if ($call->summary_received_at === null && $moment !== null && ($call->started_at === null || $moment->lessThan($call->started_at))) {
            $call->started_at = $moment;
        }

        $this->inferFromLegs($call);
    }

    /**
     * The employee connected with the client in this event: exactly one side is an
     * employee (has an extension), the other is the client. Two extensions are a
     * consultation between employees, not an answer to the client.
     *
     * @param  CallEvent  $data
     */
    private function clientConnection(array $data): ?string
    {
        if ($data['call_state'] !== 'Connected') {
            return null;
        }

        $from = $data['from']['extension'];
        $to = $data['to']['extension'];

        return match (true) {
            $from === null && $to !== null => $to,
            $from !== null && $to === null => $from,
            default => null,
        };
    }

    /**
     * Before the summary the call is described by its first leg that was not taken from
     * another one. A command or callback leg marks the whole call as technical.
     */
    private function inferFromLegs(Call $call): void
    {
        $roots = CallLeg::query()
            ->where('call_id', $call->id)
            ->whereNull('taken_from_call_id')
            ->orderBy('appeared_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($roots->contains('technical', true)) {
            $call->technical = true;
        }

        $root = $roots->first();

        if ($root === null) {
            return;
        }

        $call->line_number ??= $root->line_number;

        if ($call->direction_final) {
            return;
        }

        $direction = match (true) {
            $root->from_extension === null && $root->line_number !== null && ! MangoEventContract::isSip($root->from_number) => CallDirection::Inbound,
            $root->from_extension !== null && $root->to_extension === null => CallDirection::Outbound,
            $root->from_extension !== null && $root->to_extension !== null => CallDirection::Internal,
            default => null,
        };

        $call->direction ??= $direction;

        if ($call->client_number === null) {
            $call->client_number = match ($call->direction) {
                CallDirection::Inbound => $root->from_number,
                CallDirection::Outbound => $root->to_number,
                default => null,
            };
        }
    }

    /**
     * The summary is final for the call (3.1.6): direction, line, start, end and result.
     * It is applied once; later realtime events only add legs.
     *
     * @param  SummaryEvent  $data
     */
    private function applySummary(Call $call, array $data): void
    {
        if ($call->summary_received_at !== null) {
            return;
        }

        $direction = CallDirection::fromSummary($data['call_direction']);
        $answered = $data['entry_result'] === 1 && $data['talk_time'] > 0;
        $client = match ($direction) {
            CallDirection::Inbound => $data['from']['number'],
            CallDirection::Outbound => $data['to']['number'],
            default => null,
        };

        $call->fill([
            'summary_received_at' => CarbonImmutable::now(),
            'direction' => $direction,
            'direction_final' => true,
            'line_number' => $data['line_number'] ?? $call->line_number,
            'client_number' => $client ?? ($direction === CallDirection::Internal ? null : $call->client_number),
            'started_at' => MangoEventContract::moment($data['create_time']) ?? $call->started_at,
            'ended_at' => MangoEventContract::moment($data['end_time']),
            'result' => $answered ? Call::ANSWERED : Call::MISSED,
            'answered_at' => $answered ? MangoEventContract::moment($data['talk_time']) : null,
            'talk_seconds' => $answered && $data['end_time'] >= $data['talk_time'] ? $data['end_time'] - $data['talk_time'] : null,
            'disconnect_reason' => $data['disconnect_reason'],
            'summary_extension' => ! $answered ? null : match ($direction) {
                CallDirection::Inbound => $data['to']['extension'],
                CallDirection::Outbound => $data['from']['extension'],
                default => null,
            },
        ]);
    }

    /**
     * Matching and assignment from everything the call knows now. Safe to repeat.
     */
    private function settle(Call $call, IntegrationConnection $connection): void
    {
        if ($call->order_id === null) {
            $this->match($call, $connection);
        }

        $incoming = $call->direction === CallDirection::Inbound && ! $call->technical;
        [$answerer] = $this->answerer($call, $connection, $call->showroom_id);

        if ($call->order_id !== null && $incoming && $answerer !== null) {
            $this->offer($call, $answerer);
        }

        if (! $incoming) {
            $call->answerer_unresolved = false;
            $call->answer_ambiguous = false;
        }

        $call->needs_review = $call->match_state->needsReview() || $call->answerer_unresolved || $call->answer_ambiguous;
        $call->save();
    }

    private function match(Call $call, IntegrationConnection $connection): void
    {
        $direction = $call->direction;

        if ($call->technical) {
            $call->match_state = CallMatchState::Technical;

            return;
        }

        if ($direction === null) {
            $call->match_state = CallMatchState::Pending;

            return;
        }

        if ($direction === CallDirection::Internal) {
            $call->match_state = CallMatchState::Internal;

            return;
        }

        if ($call->line_number === null) {
            $call->match_state = $call->summary_received_at === null ? CallMatchState::Pending : CallMatchState::UnknownLine;

            return;
        }

        [$line, $state] = $this->line($connection, $call->line_number);
        $call->integration_line_id = $line?->id;

        if ($state !== null) {
            $call->source_id = null;
            $call->showroom_id = null;
            $call->match_state = $state;

            return;
        }

        /** @var Source $source */
        $source = $line->binding->source;
        $call->source_id = $source->id;
        $call->showroom_id = $source->showroom_id;

        $phone = MangoEventContract::isSip($call->client_number) || $call->client_number === null ? null : Phone::normalize($call->client_number);
        $call->client_phone = $phone;

        if ($phone === null) {
            $call->match_state = CallMatchState::NoClientPhone;

            return;
        }

        $order = $this->orders->findForCall($source->showroom_id, $phone);

        if ($order === null && $direction === CallDirection::Inbound) {
            $channelId = $this->directCallChannel($source);

            if ($channelId === null) {
                $call->match_state = CallMatchState::SourceUnavailable;

                return;
            }

            [$answerer] = $this->answerer($call, $connection, $source->showroom_id);
            $order = $this->orders->createForCall($call, $source, $channelId, $answerer['user'] ?? null, $this->firstReceivedAt($call));
            $call->order_created = true;

            if ($answerer !== null) {
                $call->assignment_decided_at = CarbonImmutable::now();
                $call->assigned_connected_at = $answerer['at'];
                $call->assigned_user_id = $answerer['user']->id;
            }
        }

        if ($order === null) {
            $call->match_state = CallMatchState::NoOrder;

            return;
        }

        $call->order_id = $order->id;
        $call->match_state = CallMatchState::Linked;
    }

    /**
     * The line of this connection: exact match_key, a SIP address with or without the
     * `sip:` prefix. Nothing is guessed: no line or several lines stop the matching, and
     * so does a source that cannot accept calls of this connection.
     *
     * @return array{0: IntegrationLine|null, 1: CallMatchState|null}
     */
    private function line(IntegrationConnection $connection, string $raw): array
    {
        $query = IntegrationLine::query()->where('integration_connection_id', $connection->id);

        if (MangoEventContract::isSip($raw)) {
            $value = mb_strtolower(trim($raw));
            $query->where('type', IntegrationLine::SIP)->whereIn('match_key', array_unique([$value, preg_replace('/^sip:/', '', $value)]));
        } else {
            $query->where('type', IntegrationLine::PHONE)->where('match_key', IntegrationLine::matchKey(IntegrationLine::PHONE, $raw));
        }

        $lines = $query->with('binding.source.showroom')->limit(2)->get();

        if ($lines->count() !== 1) {
            return [null, $lines->isEmpty() ? CallMatchState::UnknownLine : CallMatchState::AmbiguousLine];
        }

        /** @var IntegrationLine $line */
        $line = $lines->first();
        $source = $line->binding->source;
        $allowed = $source->active
            && $source->transfer_from_showroom_id === null
            && $source->showroom->active
            && $connection->active
            && $connection->showrooms()->whereKey($source->showroom_id)->exists();

        return [$line, $allowed ? null : CallMatchState::SourceUnavailable];
    }

    private function directCallChannel(Source $source): ?int
    {
        $id = DB::table('source_channel')
            ->join('contact_channels', 'contact_channels.id', '=', 'source_channel.contact_channel_id')
            ->where('source_channel.source_id', $source->id)
            ->where('contact_channels.code', ContactChannel::DIRECT_CALL)
            ->where('contact_channels.active', true)
            ->value('contact_channels.id');

        return $id === null ? null : (int) $id;
    }

    private function firstReceivedAt(Call $call): CarbonImmutable
    {
        $first = MangoEvent::query()
            ->where('integration_connection_id', $call->integration_connection_id)
            ->where('entry_id', $call->entry_id)
            ->min('received_at');

        return $first === null ? CarbonImmutable::now() : CarbonImmutable::parse($first);
    }

    /**
     * Offers the answerer of an incoming call to its order once per call: the first time
     * the call knows an answerer and an order. Only an order without a responsible takes
     * him (OrderManager::assignFromCall); a responsible set by hand, by distribution or by
     * another call stays. A decided call never offers again, so a repeated event, job or
     * rematch — or a late leg of a transfer — cannot assign after a manual removal.
     *
     * @param  array{user: User, at: CarbonImmutable}  $answerer
     */
    private function offer(Call $call, array $answerer): void
    {
        if ($call->assignment_decided_at !== null) {
            return;
        }

        $call->assignment_decided_at = CarbonImmutable::now();

        if ($this->orders->assignFromCall((int) $call->order_id, $answerer['user'])) {
            $call->assigned_connected_at = $answerer['at'];
            $call->assigned_user_id = $answerer['user']->id;
        }
    }

    /**
     * The earliest employee by connection time who answered the client and may take
     * orders of the showroom; also records on the call and its legs who answered. Every
     * answerer of a transfer stays on its leg. Equal earliest moments of different
     * employees are ambiguous and decide nothing.
     *
     * @return array{0: array{user: User, at: CarbonImmutable}|null}
     */
    private function answerer(Call $call, IntegrationConnection $connection, ?int $showroomId): array
    {
        $legs = CallLeg::query()->where('call_id', $call->id)->whereNotNull('connected_at')->whereNotNull('answered_extension')->lockForUpdate()->get();

        /** @var Collection<int, array{extension: string, at: CarbonImmutable}> $facts */
        $facts = $legs->map(fn (CallLeg $leg) => ['extension' => (string) $leg->answered_extension, 'at' => $leg->connected_at]);

        if ($facts->isEmpty() && $call->summary_extension !== null && $call->answered_at !== null) {
            $facts = collect([['extension' => $call->summary_extension, 'at' => $call->answered_at]]);
        }

        $mapped = TelephonyExtension::query()
            ->where('integration_connection_id', $connection->id)
            ->whereIn('extension', $facts->pluck('extension')->unique()->all())
            ->pluck('user_id', 'extension');

        foreach ($legs as $leg) {
            $leg->answered_by_id = $mapped[$leg->answered_extension] ?? null;

            if ($leg->isDirty('answered_by_id')) {
                $leg->save();
            }
        }

        $eligible = $showroomId === null ? collect() : $this->eligible($mapped->values()->unique()->all(), $showroomId);
        $candidates = $facts
            ->map(fn (array $fact) => [...$fact, 'user' => $eligible->get($mapped[$fact['extension']] ?? 0)])
            ->sortBy(fn (array $fact) => $fact['at']->getTimestamp());

        $usable = $candidates->filter(fn (array $fact) => $fact['user'] !== null)->values();
        $call->answerer_unresolved = $candidates->isNotEmpty() && $usable->isEmpty();

        if ($usable->isEmpty()) {
            $call->answered_by_id = null;
            $call->answered_extension = $candidates->first()['extension'] ?? null;
            $call->answer_ambiguous = false;

            return [null];
        }

        $top = $usable->first();
        $tied = $usable->filter(fn (array $fact) => $fact['at']->equalTo($top['at']))->pluck('user.id')->unique();
        $call->answer_ambiguous = $tied->count() > 1;

        if ($call->answer_ambiguous) {
            $call->answered_by_id = null;
            $call->answered_extension = null;

            return [null];
        }

        $call->answered_by_id = $top['user']->id;
        $call->answered_extension = $top['extension'];

        return [['user' => $top['user'], 'at' => $top['at']]];
    }

    /**
     * Active employees with `orders.view` and access to the showroom. The distribution
     * schedule does not matter: it is about who gets callbacks, not who answered.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    private function eligible(array $userIds, int $showroomId): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return User::query()
            ->whereKey($userIds)
            ->where('active', true)
            ->permission(P::ORDERS_VIEW)
            ->where(fn (Builder $query) => $query
                ->where('has_global_access', true)
                ->orWhereHas('showrooms', fn (Builder $showrooms) => $showrooms->where('showrooms.id', $showroomId)))
            ->get(['id', 'name'])
            ->keyBy('id')
            ->toBase();
    }
}
