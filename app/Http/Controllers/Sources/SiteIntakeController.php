<?php

namespace App\Http\Controllers\Sources;

use App\Enums\SiteRequestState;
use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Models\SiteCallbackRequest;
use App\Models\Source;
use App\Models\User;
use App\Services\SiteIntakeKeyManager;
use App\Services\SiteRequestProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Site API settings of one source for a full administrator: the intake address, the
 * key (shown only in the answer that issues it, never in page props) and the latest
 * requests with their processing state. No payload, IP or secret is returned; a linked
 * order is named only when the administrator may open it.
 */
class SiteIntakeController extends Controller
{
    private const LATEST = 20;

    public function __construct(
        private readonly SiteIntakeKeyManager $keys,
        private readonly SiteRequestProcessor $processor,
    ) {}

    public function show(Request $request, Source $source): JsonResponse
    {
        $this->authorize('viewAny', IntegrationConnection::class);
        $this->authorize('view', $source);

        /** @var User $user */
        $user = $request->user();
        $intake = $source->siteIntake;

        $requests = SiteCallbackRequest::query()
            ->where('source_id', $source->id)
            ->with('order')
            ->orderByDesc('id')
            ->limit(self::LATEST)
            ->get();

        return response()->json([
            'source_id' => $source->id,
            'endpoint' => route('api.callback-requests.store'),
            'intake' => $intake === null ? null : [
                'active' => $intake->active,
                'has_key' => $intake->key_hash !== null,
                'key_hint' => $intake->key_hint,
                'key_issued_at' => $intake->key_issued_at?->toISOString(),
            ],
            'requests' => $requests->map(fn (SiteCallbackRequest $item) => [
                'receipt_id' => $item->receipt_id,
                'received_at' => $item->received_at->toISOString(),
                'event_at' => $item->event_at->toISOString(),
                'state' => $item->state->value,
                'state_label' => $item->state->label(),
                'order_id' => $item->order !== null && $user->can('view', $item->order) ? $item->order->id : null,
                'error' => $item->errorLabel(),
                'attempts' => $item->attempts,
                'can_retry' => $item->state !== SiteRequestState::Processed,
            ])->values(),
            'can' => ['manageKey' => $user->can('manageSecrets', IntegrationConnection::class)],
        ]);
    }

    public function issueKey(Source $source): JsonResponse
    {
        $this->authorize('manageSecrets', IntegrationConnection::class);
        $this->authorize('view', $source);

        return response()
            ->json(['key' => $this->keys->issue($source)])
            ->header('Cache-Control', 'no-store');
    }

    public function revokeKey(Source $source): JsonResponse
    {
        $this->authorize('manageSecrets', IntegrationConnection::class);
        $this->authorize('view', $source);

        $this->keys->revoke($source);

        return response()->json(['revoked' => true]);
    }

    public function retry(Request $request, SiteCallbackRequest $siteRequest): JsonResponse
    {
        $this->authorize('viewAny', IntegrationConnection::class);
        $this->authorize('view', $siteRequest->source);

        $this->processor->retry($siteRequest, $request->user());

        return response()->json(['state' => SiteCallbackRequest::query()->whereKey($siteRequest->id)->value('state')]);
    }
}
