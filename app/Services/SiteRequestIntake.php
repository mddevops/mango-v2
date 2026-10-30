<?php

namespace App\Services;

use App\Enums\SiteRequestState;
use App\Exceptions\IdempotencyConflict;
use App\Exceptions\SiteIntakeUnavailable;
use App\Models\ContactChannel;
use App\Models\SiteCallbackRequest;
use App\Models\Source;
use App\Models\SourceSiteIntake;
use App\Support\SiteCallbackContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accepts a site request: authenticates the key of the source, checks that the source
 * may accept requests and saves the request before the site gets its receipt. The order
 * is created later from the saved request (SiteRequestProcessor).
 *
 * The pair source + Idempotency-Key is unique in the database. A repeated delivery with
 * the same normalized data returns the first receipt; other data under the same key is
 * a conflict. Concurrent identical deliveries meet at the unique index. Without the key
 * every HTTP request is a new request: equal data is never merged.
 *
 * @phpstan-import-type Normalized from SiteCallbackContract
 */
class SiteRequestIntake
{
    public function __construct(private readonly BusinessDayCalculator $businessDays) {}

    /**
     * The intake whose key matches. A key opens only its own source, so another
     * `source_id` with a valid key of a different source is rejected.
     */
    public function authenticate(int $sourceId, ?string $key): ?SourceSiteIntake
    {
        if ($key === null || $key === '' || strlen($key) > 200) {
            return null;
        }

        $intake = SourceSiteIntake::query()->where('source_id', $sourceId)->whereNotNull('key_hash')->first();

        return $intake !== null && hash_equals((string) $intake->key_hash, hash('sha256', $key)) ? $intake : null;
    }

    /**
     * @param  Normalized  $data
     * @return array{request: SiteCallbackRequest, created: bool}
     *
     * @throws IdempotencyConflict
     * @throws SiteIntakeUnavailable
     */
    public function accept(int $sourceId, ?string $idempotencyKey, array $data): array
    {
        $fingerprint = SiteCallbackContract::fingerprint($sourceId, $data);
        $existing = $idempotencyKey === null ? null : $this->existing($sourceId, $idempotencyKey, $fingerprint);

        if ($existing !== null) {
            return ['request' => $existing, 'created' => false];
        }

        [$source, $channelId] = $this->available($sourceId);

        $receivedAt = CarbonImmutable::now();
        $eventAt = ($data['created_at'] ?? $receivedAt)->utc();
        $day = $this->businessDays->forShowroom($source->showroom, $eventAt);

        try {
            $request = SiteCallbackRequest::create([
                'receipt_id' => Str::lower((string) Str::ulid()),
                'source_id' => $source->id,
                'showroom_id' => $source->showroom_id,
                'contact_channel_id' => $channelId,
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'comment' => $data['comment'],
                'visitor_ip' => $data['ip_address'],
                'url' => $data['url'],
                'utm_source' => $data['utm_source'],
                'utm_medium' => $data['utm_medium'],
                'utm_campaign' => $data['utm_campaign'],
                'utm_term' => $data['utm_term'],
                'utm_content' => $data['utm_content'],
                'form' => $data['form'],
                'viewed_vehicles' => SiteCallbackContract::storedVehicles($data['viewed_vehicles']),
                'event_at' => $eventAt,
                'event_time_reported' => $data['created_at'] !== null,
                'received_at' => $receivedAt,
                'business_date' => $day->date,
                'business_day_rule' => $day->rule->snapshot(),
                'state' => SiteRequestState::Accepted,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            if ($idempotencyKey === null || ! str_contains($exception->getMessage(), 'site_callback_requests_source_key_unique')) {
                throw $exception;
            }

            return ['request' => $this->existing($sourceId, $idempotencyKey, $fingerprint) ?? throw $exception, 'created' => false];
        }

        return ['request' => $request, 'created' => true];
    }

    /**
     * @throws IdempotencyConflict
     */
    private function existing(int $sourceId, string $idempotencyKey, string $fingerprint): ?SiteCallbackRequest
    {
        $request = SiteCallbackRequest::query()
            ->where('source_id', $sourceId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($request !== null && ! hash_equals($request->fingerprint, $fingerprint)) {
            throw new IdempotencyConflict;
        }

        return $request;
    }

    /**
     * Nothing is substituted: a disabled source, showroom, intake or channel stops the
     * request.
     *
     * @return array{0: Source, 1: int}
     *
     * @throws SiteIntakeUnavailable
     */
    private function available(int $sourceId): array
    {
        /** @var Source|null $source */
        $source = Source::query()->with(['showroom', 'siteIntake'])->find($sourceId);

        if ($source === null || ! $source->active) {
            throw new SiteIntakeUnavailable('Источник отключён.');
        }

        if ($source->transfer_from_showroom_id !== null) {
            throw new SiteIntakeUnavailable('Служебный источник передачи между салонами не принимает обращения.');
        }

        if (! $source->showroom->active) {
            throw new SiteIntakeUnavailable('Салон источника отключён.');
        }

        if ($source->siteIntake === null || ! $source->siteIntake->active) {
            throw new SiteIntakeUnavailable('Приём заявок с сайта для источника отключён.');
        }

        $channelId = DB::table('source_channel')
            ->join('contact_channels', 'contact_channels.id', '=', 'source_channel.contact_channel_id')
            ->where('source_channel.source_id', $source->id)
            ->where('contact_channels.code', ContactChannel::CALLBACK)
            ->where('contact_channels.active', true)
            ->value('contact_channels.id');

        if ($channelId === null) {
            throw new SiteIntakeUnavailable('Канал «Обратный звонок» отключён или не разрешён источнику.');
        }

        return [$source, (int) $channelId];
    }
}
