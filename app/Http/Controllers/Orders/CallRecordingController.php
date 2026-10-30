<?php

namespace App\Http\Controllers\Orders;

use App\Enums\CallRecordingState;
use App\Exceptions\MangoPlaybackFailure;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\CallRecording;
use App\Models\Order;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\AuditLogger;
use App\Services\MangoRecordingPlayer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Listening to a call recording inside the order card. Every request checks the order
 * scope, `orders.history` and `orders.recordings`, and that the recording belongs to a
 * call of this order; a recording without a linked order is never served. The audio is
 * streamed from Mango through this route (MangoRecordingPlayer): the browser never gets
 * a Mango address, a key or a signature, and nothing is cached.
 */
class CallRecordingController extends Controller
{
    /** One playback is deduplicated for this long; a new playback has a new id. */
    private const PLAYBACK_TTL_HOURS = 12;

    private const PRIVATE = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
        private readonly MangoRecordingPlayer $player,
    ) {}

    /**
     * GET passes Mango's 200, 206 or 416 through; HEAD only asks Mango for a file link
     * and never follows it. Errors are short safe texts: 404 — Mango has no recording to
     * give now, 502 — playback failed.
     */
    public function audio(Request $request, int $order, int $recording): Response
    {
        [, $model] = $this->resolve($request, $order, $recording);

        try {
            if ($request->isMethod('HEAD')) {
                $this->player->check($model);

                return response('', 200, ['Content-Type' => 'audio/mpeg', ...self::PRIVATE]);
            }

            $audio = $this->player->open($model, MangoRecordingPlayer::range($request->header('Range')));
        } catch (MangoPlaybackFailure $failure) {
            Log::warning('Mango recording playback failed.', [
                'call_recording_id' => $model->id,
                'connection_id' => $model->integration_connection_id,
                'error_code' => $failure->errorCode,
                ...$failure->diagnostics,
            ]);

            return response(
                $failure->unavailable ? 'Запись пока недоступна' : 'Не удалось воспроизвести',
                $failure->unavailable ? 404 : 502,
                ['Content-Type' => 'text/plain; charset=UTF-8', ...self::PRIVATE],
            );
        }

        if (! $audio->hasBody()) {
            return response('', $audio->status, [...$audio->headers, ...self::PRIVATE]);
        }

        return new StreamedResponse(function () use ($audio) {
            set_time_limit(max(60, (int) config('services.mango.recordings.stream_seconds')));
            $audio->send();
        }, $audio->status, [...$audio->headers, ...self::PRIVATE]);
    }

    /**
     * The start of one playback, sent by the player on «play»: recorded once per playback
     * id, so pause, seeking, Range requests and a retry of the same playback add nothing.
     * It says only that listening started, never how much was heard.
     */
    public function play(Request $request, int $order, int $recording): JsonResponse
    {
        $data = $request->validate(['playback' => ['required', 'uuid']]);
        [$orderModel, $model] = $this->resolve($request, $order, $recording);

        /** @var User $user */
        $user = $request->user();
        $key = "call-recording-play:{$user->id}:{$model->id}:".strtolower($data['playback']);
        $first = Cache::add($key, true, now()->addHours(self::PLAYBACK_TTL_HOURS));

        if ($first) {
            $this->audit->record('order.recording_played', 'order', $orderModel->id, "Заявка №{$orderModel->id}", 'Начато прослушивание записи разговора', [
                'recording' => "№{$model->id}",
            ], [$orderModel->showroom_id]);
        }

        return response()->json(['recorded' => $first]);
    }

    /**
     * @return array{0: Order, 1: CallRecording}
     */
    private function resolve(Request $request, int $order, int $recording): array
    {
        /** @var User $user */
        $user = $request->user();

        $orderModel = $this->scope->orders(Order::query(), $user)->findOrFail($order);
        $this->authorize('listenRecordings', $orderModel);

        $model = CallRecording::query()
            ->whereKey($recording)
            ->where('state', CallRecordingState::Recorded)
            ->whereIn('call_id', Call::query()->select('id')->where('order_id', $orderModel->id))
            ->firstOrFail();

        return [$orderModel, $model];
    }
}
