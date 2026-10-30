<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\IdempotencyConflict;
use App\Exceptions\SiteIntakeUnavailable;
use App\Http\Controllers\Controller;
use App\Services\SiteRequestIntake;
use App\Services\SiteRequestProcessor;
use App\Support\SiteCallbackContract;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `POST /api/v1/callback-requests` — a callback request from a site's server. The 202
 * answer means "saved"; the order and its responsible come later from the queue. No
 * answer reveals ids of the CRM, repeats, responsibles or internal errors.
 *
 * The body is JSON or an ordinary form (application/x-www-form-urlencoded); both go
 * through one validation and normalization. Idempotency-Key is optional: without it every
 * request is new, with it a repeated delivery returns the first receipt.
 *
 * Limits count the actual sender IP: every request per IP, and accepted keys per source
 * and IP. The `ip_address` field of the body is never used for them.
 */
class CallbackRequestController extends Controller
{
    public function __construct(
        private readonly SiteRequestIntake $intake,
        private readonly SiteRequestProcessor $processor,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $ip = (string) $request->ip();

        if ($limited = $this->limit('site-api-ip:'.$ip, (int) config('services.site_api.ip_per_minute'))) {
            return $limited;
        }

        if (strlen($request->getContent()) > SiteCallbackContract::MAX_BYTES) {
            return $this->error(413, 'payload_too_large', 'Размер запроса больше '.(SiteCallbackContract::MAX_BYTES / 1024).' КБ.');
        }

        if ($request->isJson()) {
            $body = json_decode($request->getContent(), true);

            if (! is_array($body) || ($body !== [] && array_is_list($body))) {
                return $this->error(400, 'invalid_json', 'Тело запроса должно быть JSON-объектом.');
            }
        } elseif (str_starts_with(strtolower((string) $request->header('Content-Type')), 'application/x-www-form-urlencoded')) {
            $body = SiteCallbackContract::fromForm($request->request->all());
        } else {
            return $this->error(415, 'unsupported_media_type', 'Отправьте тело запроса с заголовком Content-Type: application/json или application/x-www-form-urlencoded.');
        }

        $token = $request->bearerToken();

        if ($token === null) {
            return $this->error(401, 'unauthorized', 'Передайте ключ API в заголовке Authorization: Bearer.');
        }

        $sourceId = $body['source_id'] ?? null;

        if (! is_int($sourceId) || $sourceId < 1) {
            return $this->invalid(['source_id' => ['Укажите source_id — целый идентификатор источника.']]);
        }

        if ($this->intake->authenticate($sourceId, $token) === null) {
            return $this->error(401, 'unauthorized', 'Ключ API не подходит для этого источника.');
        }

        if ($limited = $this->limit("site-api:{$sourceId}:{$ip}", (int) config('services.site_api.per_minute'))) {
            return $limited;
        }

        $key = $request->headers->has('Idempotency-Key') ? (string) $request->headers->get('Idempotency-Key') : null;

        if ($key !== null && preg_match(SiteCallbackContract::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
            return $this->invalid(['Idempotency-Key' => ['Idempotency-Key необязателен, но если передан — от 8 до 100 символов: латиница, цифры, «.», «_», «:», «-» (например, UUID).']]);
        }

        $validator = SiteCallbackContract::validator($body);

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        try {
            $result = $this->intake->accept($sourceId, $key, SiteCallbackContract::normalize($validator->validated()));
        } catch (IdempotencyConflict) {
            return $this->error(409, 'idempotency_conflict', 'Этот Idempotency-Key уже использован для другого обращения. Для новой отправки формы создайте новый ключ.');
        } catch (SiteIntakeUnavailable $exception) {
            return $this->error(403, 'intake_unavailable', $exception->getMessage());
        } catch (QueryException $exception) {
            report($exception);

            return $this->error(503, 'storage_failed', 'Обращение не сохранено. Повторите запрос позже (с тем же Idempotency-Key, если он был передан).');
        }

        if ($result['created']) {
            $this->processor->queue($result['request']);
        }

        return response()->json(['receipt_id' => $result['request']->receipt_id, 'status' => 'accepted'], 202);
    }

    private function limit(string $key, int $perMinute): ?JsonResponse
    {
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            return $this->error(429, 'too_many_requests', 'Слишком много запросов. Повторите позже.')
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function invalid(array $errors): JsonResponse
    {
        return response()->json(['error' => 'validation_failed', 'message' => 'Проверьте данные обращения.', 'errors' => $errors], 422);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
