<?php

namespace App\Services;

use App\Enums\MangoEventState;
use App\Exceptions\MangoEventFailure;
use App\Jobs\ProcessMangoEvent;
use App\Models\MangoEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies saved Mango events to their calls, recording events to their recordings. Every
 * event may be applied again: the call keeps per-leg seq, the summary flag and the
 * assignment marker, the recording its seq and state, so a repeat changes nothing. Attempts are limited; after the last one the event stays in an explicit error
 * state until a manual retry. The same scheme as the site API (SiteRequestProcessor).
 */
class MangoEventProcessor
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly MangoCallService $calls,
        private readonly MangoRecordingService $recordings,
        private readonly AuditLogger $audit,
        private readonly Dispatcher $dispatcher,
    ) {}

    /**
     * Throws only MangoEventFailure (safe for logs, the worker output and failed_jobs);
     * the queue repeats the job until the attempts are spent.
     */
    public function process(int $eventId): void
    {
        try {
            $this->attempt($eventId);
        } catch (Throwable $exception) {
            $failure = MangoEventFailure::from($exception);

            if ($failure !== $exception) {
                Log::warning('Mango event processing failed.', ['event_id' => $eventId, 'error_code' => $failure->errorCode, ...$failure->diagnostics]);
            }

            throw $failure;
        }
    }

    private function attempt(int $eventId): void
    {
        $now = CarbonImmutable::now();
        $started = MangoEvent::query()
            ->whereKey($eventId)
            ->where('state', MangoEventState::Accepted)
            ->increment('attempts', 1, ['last_attempt_at' => $now, 'updated_at' => $now]);

        if ($started === 0) {
            return;
        }

        try {
            $kind = MangoEvent::query()->whereKey($eventId)->value('kind');

            if (in_array($kind, MangoEvent::RECORDING_KINDS, true)) {
                $this->recordings->apply($eventId);
            } else {
                $this->calls->apply($eventId);
            }

            MangoEvent::query()
                ->whereKey($eventId)
                ->where('state', MangoEventState::Accepted)
                ->update(['state' => MangoEventState::Processed, 'processed_at' => CarbonImmutable::now(), 'error_code' => null]);
        } catch (Throwable $exception) {
            $failure = MangoEventFailure::from($exception);
            $event = MangoEvent::query()->whereKey($eventId)->first(['id', 'integration_connection_id', 'attempts']);
            $final = (int) $event?->attempts >= self::MAX_ATTEMPTS || ! $failure->retryable;

            MangoEvent::query()
                ->whereKey($eventId)
                ->where('state', MangoEventState::Accepted)
                ->update([
                    'error_code' => $this->errorCode($failure),
                    ...($final ? ['state' => MangoEventState::Failed] : []),
                ]);

            Log::warning('Mango event processing failed.', [
                'event_id' => $eventId,
                'connection_id' => $event?->integration_connection_id,
                'error_code' => $this->errorCode($failure),
                'final' => $final,
                ...$failure->diagnostics,
            ]);

            if ($final) {
                return;
            }

            throw $failure;
        }
    }

    /**
     * The queue gave up (attempts exhausted or the worker was killed).
     */
    public function markFailed(int $eventId, ?Throwable $exception): void
    {
        $event = MangoEvent::query()->whereKey($eventId)->where('state', MangoEventState::Accepted)->first();

        $event?->forceFill([
            'state' => MangoEventState::Failed,
            'error_code' => $event->error_code ?? ($exception === null ? 'processing_error' : $this->errorCode($exception)),
        ])->save();
    }

    /**
     * Hands a saved event to the queue. A failure to queue does not undo the acceptance:
     * the recovery command picks the event up.
     */
    public function queue(MangoEvent $event): void
    {
        try {
            $this->dispatcher->dispatch(new ProcessMangoEvent($event->id));
        } catch (Throwable $exception) {
            Log::warning('Mango event was not processed from the queue.', [
                'event_id' => $event->id,
                'exception' => $exception::class,
            ]);

            MangoEvent::query()
                ->whereKey($event->id)
                ->where('state', MangoEventState::Accepted)
                ->whereNull('error_code')
                ->update(['error_code' => 'not_queued']);
        }
    }

    /**
     * A manual retry: a failed (or stuck) event gets a fresh set of attempts.
     */
    public function retry(MangoEvent $event, ?User $actor): void
    {
        $reset = MangoEvent::query()
            ->whereKey($event->id)
            ->whereIn('state', [MangoEventState::Failed, MangoEventState::Accepted])
            ->update(['state' => MangoEventState::Accepted, 'attempts' => 0, 'error_code' => null, 'updated_at' => CarbonImmutable::now()]);

        if ($reset === 0) {
            return;
        }

        $this->audit->as($actor, fn () => $this->recordRetry($event));

        $this->queue($event);
    }

    /**
     * Processes saved events the queue has not handled, oldest first, in this process.
     * Safe next to a running worker: every event is applied under the lock of its call.
     *
     * @return array{processed: int, failed: int}
     */
    public function processPending(int $limit, int $olderThanSeconds): array
    {
        $ids = MangoEvent::query()
            ->where('state', MangoEventState::Accepted)
            ->where('received_at', '<=', CarbonImmutable::now()->subSeconds($olderThanSeconds))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        return $this->runInline($ids->all());
    }

    /**
     * @return array{processed: int, failed: int}
     */
    public function retryFailed(int $limit, ?int $eventId = null): array
    {
        $events = MangoEvent::query()
            ->where('state', MangoEventState::Failed)
            ->when($eventId !== null, fn ($query) => $query->whereKey($eventId))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($events as $event) {
            $event->forceFill(['state' => MangoEventState::Accepted, 'attempts' => 0, 'error_code' => null])->save();
            $this->recordRetry($event);
        }

        return $this->runInline($events->modelKeys());
    }

    private function recordRetry(MangoEvent $event): void
    {
        $connection = $event->connection()->first(['id', 'name']);

        $this->audit->record('mango_event.retried', 'integration_connection', $event->integration_connection_id, (string) $connection?->name, 'Повторная обработка события Mango', [
            'event_id' => $event->id,
            'kind' => $event->kind,
        ], $connection?->showrooms()->pluck('showrooms.id')->all() ?? []);
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
                // The safe error code is already stored on the event.
            }

            $state = MangoEvent::query()->whereKey($id)->value('state');
            $summary[$state === MangoEventState::Processed->value ? 'processed' : 'failed']++;
        }

        return $summary;
    }

    private function errorCode(Throwable $exception): string
    {
        return $exception instanceof MangoEventFailure && isset(MangoEvent::ERRORS[$exception->errorCode])
            ? $exception->errorCode
            : 'processing_error';
    }
}
