<?php

namespace App\Services;

use App\Models\Call;
use App\Models\CallLeg;
use App\Models\Order;
use App\Models\TelephonyExtension;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The incoming call card of an employee: one per call (connection + entry_id).
 *
 * The card belongs only to the employees Mango addressed the call to — an extension in
 * a leg (`to.extension`), the one that answered — mapped to users in this connection;
 * seeing all orders gives no card. It is shown only while the employee may open the
 * call's order; a call without an order has no card. The state comes from the saved
 * call and legs, so the order of WebSocket messages does not decide it.
 */
class CallCards
{
    public const RINGING = 'ringing';

    public const TALKING = 'talking';

    public const ENDED = 'ended';

    public const MISSED = 'missed';

    /** The employee may not (or no longer) see the card; nothing else is told. */
    public const REVOKED = 'revoked';

    /**
     * @return list<int>
     */
    public function targets(Call $call): array
    {
        $legs = CallLeg::query()->where('call_id', $call->id)->get(['to_extension', 'answered_extension']);
        $extensions = $legs->pluck('to_extension')
            ->merge($legs->pluck('answered_extension'))
            ->push($call->answered_extension)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return TelephonyExtension::query()
            ->where('integration_connection_id', $call->integration_connection_id)
            ->whereIn('extension', $extensions)
            ->pluck('user_id')
            ->push($call->answered_by_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The state of the card for this employee, or «revoked» when he is not addressed by
     * the call or may not open its order.
     */
    public function state(Call $call, User $user): string
    {
        $order = $this->order($call, $user);

        return $order === null ? self::REVOKED : $this->callState($call, $this->extensions($call, $user));
    }

    /**
     * The card data; null when the employee may not see it.
     *
     * @return array<string, mixed>|null
     */
    public function card(Call $call, User $user): ?array
    {
        $order = $this->order($call, $user);

        if ($order === null) {
            return null;
        }

        $order->loadMissing(['status:id,name', 'responsible:id,name']);
        $call->loadMissing('source:id,name');

        return [
            'call_id' => $call->id,
            'state' => $this->callState($call, $this->extensions($call, $user)),
            'phone' => $call->client_phone !== null ? Phone::format($call->client_phone) : $call->client_number,
            'client' => $order->contact_name,
            'status' => $order->status->name,
            'source' => $call->source?->name,
            'responsible' => $order->responsible?->name,
            'order_id' => $order->id,
            'order_created_at' => $order->created_at?->toISOString(),
            'url' => route('orders.show', $order->id, false),
        ];
    }

    private function order(Call $call, User $user): ?Order
    {
        if (! $user->active || $call->order_id === null || ! in_array($user->id, $this->targets($call), true)) {
            return null;
        }

        $order = Order::query()->find($call->order_id);

        return $order !== null && Gate::forUser($user)->allows('view', $order) ? $order : null;
    }

    /**
     * @return list<string>
     */
    private function extensions(Call $call, User $user): array
    {
        return TelephonyExtension::query()
            ->where('integration_connection_id', $call->integration_connection_id)
            ->where('user_id', $user->id)
            ->pluck('extension')
            ->all();
    }

    /**
     * Final once Mango's summary came (its result decides answered or missed). Before it:
     * a client-employee connection still open is a talk; the employee's own legs all
     * ended mean the call is over for him; otherwise it is still ringing.
     *
     * @param  list<string>  $extensions
     */
    private function callState(Call $call, array $extensions): string
    {
        if ($call->summary_received_at !== null) {
            return $call->result === Call::ANSWERED ? self::ENDED : self::MISSED;
        }

        /** @var Collection<int, CallLeg> $legs */
        $legs = CallLeg::query()->where('call_id', $call->id)->where('technical', false)->get();
        $answered = $legs->filter(fn (CallLeg $leg) => $leg->connected_at !== null && $leg->answered_extension !== null);

        if ($answered->contains(fn (CallLeg $leg) => $leg->state !== 'Disconnected')) {
            return self::TALKING;
        }

        $own = $legs->filter(fn (CallLeg $leg) => in_array($leg->to_extension, $extensions, true) || in_array($leg->answered_extension, $extensions, true));

        if ($own->isNotEmpty() && $own->every(fn (CallLeg $leg) => $leg->state === 'Disconnected')) {
            return $own->contains(fn (CallLeg $leg) => $leg->connected_at !== null) ? self::ENDED : self::MISSED;
        }

        return $answered->isNotEmpty() ? self::ENDED : self::RINGING;
    }
}
