<?php

namespace App\Services;

use App\Enums\MangoEventState;
use App\Models\IntegrationConnection;
use App\Models\MangoEvent;
use App\Support\MangoEventContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Saves a verified Mango event before Mango gets its 200. The pair connection +
 * dedup_key is unique, so a repeated or concurrent delivery of the same event meets the
 * first row and is not saved or processed again.
 *
 * @phpstan-import-type CallEvent from MangoEventContract
 * @phpstan-import-type SummaryEvent from MangoEventContract
 * @phpstan-import-type RecordingEvent from MangoEventContract
 * @phpstan-import-type RecordAddedEvent from MangoEventContract
 */
class MangoEventIntake
{
    /**
     * @param  CallEvent|SummaryEvent|RecordingEvent|RecordAddedEvent  $event
     * @return array{event: MangoEvent, created: bool}
     */
    public function accept(IntegrationConnection $connection, array $event, string $json): array
    {
        $dedupKey = MangoEventContract::dedupKey($event);

        try {
            $saved = MangoEvent::create([
                'integration_connection_id' => $connection->id,
                'kind' => $event['kind'],
                'dedup_key' => $dedupKey,
                'entry_id' => $event['entry_id'],
                'call_id' => $event['call_id'] ?? null,
                'seq' => $event['seq'] ?? null,
                'payload' => $json,
                'occurred_at' => MangoEventContract::occurredAt($event),
                'received_at' => CarbonImmutable::now(),
                'state' => MangoEventState::Accepted,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'mango_events_connection_dedup_unique')) {
                throw $exception;
            }

            return [
                'event' => MangoEvent::query()->where('integration_connection_id', $connection->id)->where('dedup_key', $dedupKey)->firstOrFail(),
                'created' => false,
            ];
        }

        return ['event' => $saved, 'created' => true];
    }
}
