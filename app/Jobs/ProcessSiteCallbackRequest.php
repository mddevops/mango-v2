<?php

namespace App\Jobs;

use App\Services\SiteRequestProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Creates the order of one accepted site request and hands it to distribution. Carries
 * only the request id; the data stays in the database.
 */
class ProcessSiteCallbackRequest implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'site-requests';

    public int $tries = SiteRequestProcessor::MAX_ATTEMPTS;

    public function __construct(public readonly int $requestId)
    {
        $this->onQueue(self::QUEUE);
        $this->afterCommit();
    }

    /**
     * @return list<int> seconds before each retry
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(SiteRequestProcessor $processor): void
    {
        $processor->process($this->requestId);
    }

    public function failed(?Throwable $exception): void
    {
        app(SiteRequestProcessor::class)->markFailed($this->requestId, $exception);
    }
}
