<?php

namespace App\Events\Realtime;

use App\Models\Call;
use App\Models\User;
use App\Services\CallCards;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * The incoming call card of one addressed employee changed. Carries the call id, the
 * card state and a version only; the card data is fetched by an authorized request.
 * The state is read when the signal is sent, so a late signal carries the current
 * state too. «revoked» tells an addressed employee only to close the card.
 */
class CallCard extends RealtimeSignal
{
    private ?string $state = null;

    public function __construct(public readonly int $callId, public readonly int $userId)
    {
        parent::__construct();
    }

    public function broadcastAs(): string
    {
        return 'call.card';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return $this->resolve() === null ? [] : $this->personal([$this->userId]);
    }

    protected function payload(): array
    {
        return ['call_id' => $this->callId, 'state' => $this->resolve(), 'version' => (int) floor(microtime(true) * 1000)];
    }

    private function resolve(): ?string
    {
        if ($this->state !== null) {
            return $this->state;
        }

        $call = Call::query()->find($this->callId);
        $user = User::query()->where('active', true)->find($this->userId);

        return $call === null || $user === null ? null : $this->state = app(CallCards::class)->state($call, $user);
    }
}
