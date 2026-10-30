<?php

namespace App\Jobs;

use App\Services\MangoEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Applies one saved Mango event to its call. Carries only the event id; the data stays
 * in the database.
 */
class ProcessMangoEvent implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'mango-events';

    public int $tries = MangoEventProcessor::MAX_ATTEMPTS;

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue(self::QUEUE);
        $this->afterCommit();
    }

    /**
     * @return list<int> seconds before each retry
     */
    public function backoff(): array
    {
        return [10, 60, 300, 1800];
    }

    public function handle(MangoEventProcessor $processor): void
    {
        $processor->process($this->eventId);
    }

    public function failed(?Throwable $exception): void
    {
        app(MangoEventProcessor::class)->markFailed($this->eventId, $exception);
    }
}
