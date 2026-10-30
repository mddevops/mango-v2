<?php

namespace App\Http\Controllers\Sources;

use App\Enums\CallRecordingState;
use App\Enums\MangoEventState;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\CallRecording;
use App\Models\IntegrationConnection;
use App\Models\MangoEvent;
use App\Models\TelephonyExtension;
use App\Models\User;
use App\Services\MangoCallService;
use App\Services\MangoEventProcessor;
use App\Services\TelephonyExtensionManager;
use App\Support\Phone;
use App\Support\PermissionCatalog as P;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Event intake of one Mango connection for a full administrator: the address for the
 * account settings, extensions of employees, the latest accepted event, calls that need a
 * review and events that failed. Neither the key, the salt, a signature nor a raw payload
 * is returned. This is not a check with a real PBX: the connection stays «unverified».
 */
class MangoIntakeController extends Controller
{
    private const LATEST = 20;

    public function __construct(
        private readonly TelephonyExtensionManager $extensions,
        private readonly MangoCallService $calls,
        private readonly MangoEventProcessor $processor,
    ) {}

    public function show(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('update', $connection);

        /** @var User $user */
        $user = $request->user();

        $review = Call::query()
            ->where('integration_connection_id', $connection->id)
            ->where('needs_review', true)
            ->with(['source:id,name', 'showroom:id,name', 'order'])
            ->orderByDesc('id')
            ->limit(self::LATEST)
            ->get();

        $failed = MangoEvent::query()
            ->where('integration_connection_id', $connection->id)
            ->where(fn ($query) => $query->where('state', MangoEventState::Failed)->orWhere(fn ($stuck) => $stuck->where('state', MangoEventState::Accepted)->whereNotNull('error_code')))
            ->orderByDesc('id')
            ->limit(self::LATEST)
            ->get();

        $lastEvent = MangoEvent::query()->where('integration_connection_id', $connection->id)->max('received_at');

        $recordingCounts = CallRecording::query()
            ->where('integration_connection_id', $connection->id)
            ->groupBy('state')
            ->selectRaw('state, count(*) as aggregate')
            ->pluck('aggregate', 'state');

        $recordingProblems = CallRecording::query()
            ->where('integration_connection_id', $connection->id)
            ->where('state', CallRecordingState::Unavailable)
            ->with('call:id,started_at')
            ->orderByDesc('id')
            ->limit(self::LATEST)
            ->get();

        return response()->json([
            'connection_id' => $connection->id,
            'webhook_url' => $connection->webhookUrl(),
            'last_event_at' => $lastEvent === null ? null : CarbonImmutable::parse($lastEvent, 'UTC')->toISOString(),
            'pending_events' => MangoEvent::query()->where('integration_connection_id', $connection->id)->where('state', MangoEventState::Accepted)->count(),
            'failed_events_count' => MangoEvent::query()->where('integration_connection_id', $connection->id)->where('state', MangoEventState::Failed)->count(),
            'review_count' => Call::query()->where('integration_connection_id', $connection->id)->where('needs_review', true)->count(),
            'extensions' => $connection->extensions()
                ->with('user:id,name,active')
                ->orderByRaw('LENGTH(extension)')
                ->orderBy('extension')
                ->get()
                ->map(fn (TelephonyExtension $item) => [
                    'id' => $item->id,
                    'extension' => $item->extension,
                    'user' => ['id' => $item->user->id, 'name' => $item->user->name, 'active' => $item->user->active],
                ])->values(),
            'users' => User::query()->where('active', true)->permission(P::ORDERS_VIEW)->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $item) => ['id' => $item->id, 'name' => $item->name])->values(),
            'review_calls' => $review->map(fn (Call $call) => [
                'id' => $call->id,
                'started_at' => $call->started_at?->toISOString(),
                'direction' => $call->direction?->label(),
                'line_number' => $call->line_number,
                'client' => $call->client_phone !== null ? Phone::format($call->client_phone) : $call->client_number,
                'source' => $call->source?->name,
                'showroom' => $call->showroom?->name,
                'problems' => array_values(array_filter([
                    $call->match_state->needsReview() ? $call->match_state->label() : null,
                    $call->answerer_unresolved ? 'Внутренний номер ответившего не сопоставлен с допустимым сотрудником'.($call->answered_extension === null ? '' : " (внутр. {$call->answered_extension})") : null,
                    $call->answer_ambiguous ? 'Несколько сотрудников ответили в один момент: ответственный не выбран' : null,
                ])),
                'order_id' => $call->order !== null && $user->can('view', $call->order) ? $call->order_id : null,
            ])->values(),
            'failed_events' => $failed->map(fn (MangoEvent $event) => [
                'id' => $event->id,
                'kind' => $event->kind,
                'received_at' => $event->received_at->toISOString(),
                'state' => $event->state->value,
                'state_label' => $event->state->label(),
                'error' => $event->errorLabel(),
                'attempts' => $event->attempts,
            ])->values(),
            // Metadata only: the audio stays at Mango, listening stays in the order card
            // under the order permissions.
            'recordings' => [
                'counts' => collect(CallRecordingState::cases())->mapWithKeys(fn (CallRecordingState $state) => [$state->value => (int) ($recordingCounts[$state->value] ?? 0)]),
                'problems' => $recordingProblems->map(fn (CallRecording $recording) => [
                    'id' => $recording->id,
                    'received_at' => $recording->received_at->toISOString(),
                    'call_started_at' => $recording->call?->started_at?->toISOString(),
                    'linked' => $recording->call_id !== null,
                    'state' => $recording->state->value,
                    'state_label' => $recording->state->label(),
                    'error_code' => $recording->error_code,
                    'error' => $recording->errorLabel(),
                ])->values(),
            ],
        ]);
    }

    public function storeExtension(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('update', $connection);

        $data = $request->validate([
            'extension' => ['required', 'string', 'regex:'.TelephonyExtension::PATTERN],
            'user_id' => ['required', 'integer'],
        ], [
            'extension.regex' => 'Внутренний номер — до 32 символов: цифры, латиница, «.», «_», «-».',
        ], ['extension' => 'внутренний номер', 'user_id' => 'сотрудник']);

        $item = $this->extensions->create($connection, trim($data['extension']), (int) $data['user_id']);

        return response()->json(['id' => $item->id], 201);
    }

    public function destroyExtension(IntegrationConnection $connection, TelephonyExtension $extension): JsonResponse
    {
        $this->authorize('update', $connection);
        abort_unless($extension->integration_connection_id === $connection->id, 404);

        $this->extensions->delete($extension);

        return response()->json(['deleted' => true]);
    }

    public function rematch(Request $request, IntegrationConnection $connection, Call $call): JsonResponse
    {
        $this->authorize('update', $connection);
        abort_unless($call->integration_connection_id === $connection->id, 404);

        $call = $this->calls->rematch($call, $request->user());

        return response()->json(['match_state' => $call->match_state->value, 'needs_review' => $call->needs_review]);
    }

    public function retry(Request $request, IntegrationConnection $connection, MangoEvent $event): JsonResponse
    {
        $this->authorize('update', $connection);
        abort_unless($event->integration_connection_id === $connection->id, 404);

        $this->processor->retry($event, $request->user());

        return response()->json(['state' => MangoEvent::query()->whereKey($event->id)->value('state')]);
    }
}
