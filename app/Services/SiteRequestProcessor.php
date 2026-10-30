<?php

namespace App\Services;

use App\Enums\SiteRequestState;
use App\Exceptions\SiteRequestFailure;
use App\Jobs\ProcessSiteCallbackRequest;
use App\Models\SiteCallbackRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns accepted site requests into orders. Every step may run again: the order is
 * created and linked to its request in one transaction, and a request that already has
 * its order only continues with distribution. Attempts are limited; after the last one
 * the request stays in an explicit error state until a manual retry.
 */
class SiteRequestProcessor
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly OrderManager $orders,
        private readonly CallbackDistributor $distributor,
        private readonly AuditLogger $audit,
        private readonly Dispatcher $dispatcher,
    ) {}

    public function process(int $requestId): void
    {
        $now = CarbonImmutable::now();
        $started = SiteCallbackRequest::query()
            ->whereKey($requestId)
            ->where('state', SiteRequestState::Accepted)
            ->update(['attempts' => DB::raw('attempts + 1'), 'last_attempt_at' => $now, 'updated_at' => $now]);

        if ($started === 0) {
            return;
        }

        try {
            $order = $this->orders->createFromSiteRequest($requestId);

            if ($order === null) {
                return;
            }

            $this->distributor->attempt($order->id);

            SiteCallbackRequest::query()
                ->whereKey($requestId)
                ->where('state', SiteRequestState::Accepted)
                ->update(['state' => SiteRequestState::Processed, 'processed_at' => CarbonImmutable::now(), 'error_code' => null]);
        } catch (Throwable $exception) {
            $attempts = (int) SiteCallbackRequest::query()->whereKey($requestId)->value('attempts');

            SiteCallbackRequest::query()
                ->whereKey($requestId)
                ->where('state', SiteRequestState::Accepted)
                ->update([
                    'error_code' => $this->errorCode($exception),
                    ...($attempts >= self::MAX_ATTEMPTS ? ['state' => SiteRequestState::Failed] : []),
                ]);

            throw $exception;
        }
    }

    /**
     * The queue gave up (attempts exhausted or the worker was killed).
     */
    public function markFailed(int $requestId, ?Throwable $exception): void
    {
        $request = SiteCallbackRequest::query()->whereKey($requestId)->where('state', SiteRequestState::Accepted)->first();

        $request?->forceFill([
            'state' => SiteRequestState::Failed,
            'error_code' => $request->error_code ?? ($exception === null ? 'processing_error' : $this->errorCode($exception)),
        ])->save();
    }

    /**
     * Hands an accepted request to the queue. A failure to queue does not undo the
     * acceptance: the recovery command picks the request up.
     */
    public function queue(SiteCallbackRequest $request): void
    {
        try {
            $this->dispatcher->dispatch(new ProcessSiteCallbackRequest($request->id));
        } catch (Throwable $exception) {
            Log::warning('Site callback request was not processed from the queue.', [
                'receipt_id' => $request->receipt_id,
                'exception' => $exception::class,
            ]);

            SiteCallbackRequest::query()
                ->whereKey($request->id)
                ->where('state', SiteRequestState::Accepted)
                ->whereNull('error_code')
                ->update(['error_code' => 'not_queued']);
        }
    }

    /**
     * A manual retry: a failed (or stuck) request gets a fresh set of attempts.
     */
    public function retry(SiteCallbackRequest $request, ?User $actor): void
    {
        $reset = SiteCallbackRequest::query()
            ->whereKey($request->id)
            ->whereIn('state', [SiteRequestState::Failed, SiteRequestState::Accepted])
            ->update(['state' => SiteRequestState::Accepted, 'attempts' => 0, 'error_code' => null, 'updated_at' => CarbonImmutable::now()]);

        if ($reset === 0) {
            return;
        }

        $request->loadMissing('source:id,name,showroom_id');

        $this->audit->as($actor, fn () => $this->audit->record(
            'site_request.retried',
            'source',
            $request->source_id,
            $request->source->name,
            'Повторная обработка обращения с сайта',
            ['receipt_id' => $request->receipt_id],
            [$request->source->showroom_id],
        ));

        $this->queue($request);
    }

    /**
     * Processes accepted requests the queue has not handled, oldest first, in this
     * process. Safe next to a running worker: every request is re-checked under its lock.
     *
     * @return array{processed: int, failed: int}
     */
    public function processPending(int $limit, int $olderThanSeconds): array
    {
        $ids = SiteCallbackRequest::query()
            ->where('state', SiteRequestState::Accepted)
            ->where('received_at', '<=', CarbonImmutable::now()->subSeconds($olderThanSeconds))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        return $this->runInline($ids->all());
    }

    /**
     * @return array{processed: int, failed: int}
     */
    public function retryFailed(int $limit, ?string $receiptId = null): array
    {
        $requests = SiteCallbackRequest::query()
            ->where('state', SiteRequestState::Failed)
            ->when($receiptId !== null, fn ($query) => $query->where('receipt_id', $receiptId))
            ->with('source:id,name,showroom_id')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($requests as $request) {
            $request->forceFill(['state' => SiteRequestState::Accepted, 'attempts' => 0, 'error_code' => null])->save();

            $this->audit->record('site_request.retried', 'source', $request->source_id, $request->source->name, 'Повторная обработка обращения с сайта', [
                'receipt_id' => $request->receipt_id,
            ], [$request->source->showroom_id]);
        }

        return $this->runInline($requests->modelKeys());
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array{processed: int, failed: int}
     */
    private function runInline(array $ids): array
    {
        $summary = ['processed' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            try {
                $this->process((int) $id);
            } catch (Throwable) {
                // The safe error code is already stored on the request.
            }

            $state = SiteCallbackRequest::query()->whereKey($id)->value('state');
            $summary[$state === SiteRequestState::Processed->value ? 'processed' : 'failed']++;
        }

        return $summary;
    }

    private function errorCode(Throwable $exception): string
    {
        return $exception instanceof SiteRequestFailure && isset(SiteCallbackRequest::ERRORS[$exception->errorCode])
            ? $exception->errorCode
            : 'processing_error';
    }
}
