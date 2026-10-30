<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Str;

/**
 * A short signal to personal channels: «something you may see has changed, ask the
 * server again». It carries ids and a version only — never order data, phones or
 * comments; the page reloads what the current rights allow. The queued job keeps only
 * these ids, and the recipients are resolved when it runs.
 */
abstract class RealtimeSignal implements ShouldBroadcast
{
    public int $tries = 3;

    public int $timeout = 15;

    /** @var list<int> */
    public array $backoff = [5, 30];

    /** Lets the browser drop a signal it has already handled. */
    public string $id;

    public function __construct()
    {
        $this->id = (string) Str::uuid();
    }

    abstract public function broadcastAs(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['type' => $this->broadcastAs(), 'id' => $this->id, ...$this->payload()];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    /**
     * @param  list<int>  $userIds
     * @return list<PrivateChannel>
     */
    protected function personal(array $userIds): array
    {
        return array_map(fn (int $id) => new PrivateChannel('App.Models.User.'.$id), $userIds);
    }
}
