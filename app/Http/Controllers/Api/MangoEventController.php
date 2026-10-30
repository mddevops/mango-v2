<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Services\MangoEventIntake;
use App\Services\MangoEventProcessor;
use App\Support\IntegrationProvider;
use App\Support\MangoEventContract;
use Illuminate\Database\QueryException;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `POST /api/mango/{webhookKey}/events/{event}` — events of the Mango VPBX account. Mango
 * reads only the HTTP code (2.2): 200 means the event is saved (or is a repeat of a saved
 * one, or a documented event this stage does not use). Nothing else is promised: whether
 * Mango repeats a request after another code is not documented.
 *
 * The parameters come as form fields (`application/x-www-form-urlencoded` in the
 * documented example); a JSON body with the same three fields is read the same way.
 * Answers carry no details, and logs carry neither the key, the signature nor the payload.
 */
class MangoEventController extends Controller
{
    public function __construct(
        private readonly MangoEventIntake $intake,
        private readonly MangoEventProcessor $processor,
    ) {}

    public function __invoke(Request $request, string $webhookKey, string $event): Response
    {
        $kind = MangoEventContract::HANDLED[$event] ?? null;

        if ($kind === null && ! in_array($event, MangoEventContract::IGNORED, true)) {
            return $this->answer(404);
        }

        $connection = strlen($webhookKey) <= 64
            ? IntegrationConnection::query()->where('webhook_key', $webhookKey)->where('provider', IntegrationProvider::MangoVpbx->value)->first()
            : null;

        if ($connection === null) {
            return $this->answer(404);
        }

        if (! $connection->active) {
            Log::notice('Mango event rejected: the connection is disabled.', ['connection_id' => $connection->id, 'event' => $event]);

            return $this->answer(403);
        }

        $apiKey = $request->input('vpbx_api_key');
        $sign = $request->input('sign');
        $json = $request->input('json');

        if (! is_string($apiKey) || ! is_string($sign) || ! is_string($json) || strlen($json) > MangoEventContract::MAX_JSON_BYTES) {
            return $this->answer(400);
        }

        if (! MangoEventContract::signatureValid($connection, $apiKey, $json, $sign)) {
            Log::notice('Mango event rejected: the key or the signature does not match.', ['connection_id' => $connection->id, 'event' => $event]);

            return $this->answer(403);
        }

        if ($kind === null) {
            return $this->answer(200);
        }

        $parsed = MangoEventContract::parse($kind, $json);

        if ($parsed === null) {
            Log::warning('Mango event rejected: the structure does not match the contract.', ['connection_id' => $connection->id, 'event' => $event]);

            return $this->answer(400);
        }

        try {
            $result = $this->intake->accept($connection, $parsed, $json);
        } catch (QueryException $exception) {
            Log::error('Mango event was not saved.', ['connection_id' => $connection->id, 'event' => $event, 'exception' => $exception::class]);

            return $this->answer(503);
        }

        if ($result['created']) {
            $this->processor->queue($result['event']);
        }

        return $this->answer(200);
    }

    private function answer(int $status): Response
    {
        return response('', $status);
    }
}
