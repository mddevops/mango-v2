<?php

namespace App\Services;

use App\Enums\CallRecordingState;
use App\Enums\MangoEventState;
use App\Exceptions\MangoEventFailure;
use App\Models\Call;
use App\Models\CallRecording;
use App\Models\MangoEvent;
use App\Support\MangoEventContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies saved recording events (`/events/recording` 3.1.4, `/events/record/added`
 * 3.1.8) to the recording metadata of a connection. A recording event never creates a
 * call or an order and never touches one: it only links the call with the same entry_id
 * when that call is already known. Nothing is fetched: the audio stays at Mango and is
 * streamed on demand (MangoRecordingPlayer).
 *
 * Lock order is the one of call events: event row → call row (or, while the call does
 * not exist yet, the index gap where it would be inserted, so a racing call event waits
 * and links the recording itself) → recording row.
 *
 * @phpstan-import-type RecordingEvent from MangoEventContract
 * @phpstan-import-type RecordAddedEvent from MangoEventContract
 */
class MangoRecordingService
{
    public function __construct(private readonly RealtimePublisher $realtime) {}

    public function apply(int $eventId): void
    {
        DB::transaction(fn () => $this->applyEvent($eventId), 3);
    }

    private function applyEvent(int $eventId): void
    {
        /** @var MangoEvent|null $event */
        $event = MangoEvent::query()->lockForUpdate()->find($eventId);

        if ($event === null || $event->state !== MangoEventState::Accepted) {
            return;
        }

        /** @var RecordingEvent|RecordAddedEvent|null $data */
        $data = in_array($event->kind, MangoEvent::RECORDING_KINDS, true) ? MangoEventContract::parse($event->kind, $event->payload) : null;

        if ($data === null) {
            throw new MangoEventFailure('invalid_payload', false);
        }

        $connectionId = $event->integration_connection_id;
        $now = CarbonImmutable::now();

        $callId = Call::query()
            ->where('integration_connection_id', $connectionId)
            ->where('entry_id', $data['entry_id'])
            ->lockForUpdate()
            ->value('id');

        DB::table('call_recordings')->insertOrIgnore([
            'integration_connection_id' => $connectionId,
            'recording_id' => $data['recording_id'],
            'entry_id' => $data['entry_id'],
            'state' => CallRecordingState::Pending->value,
            'last_seq' => 0,
            'attempts' => 0,
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var CallRecording $recording */
        $recording = CallRecording::query()
            ->where('integration_connection_id', $connectionId)
            ->where('recording_id', $data['recording_id'])
            ->lockForUpdate()
            ->firstOrFail();

        $recording->call_id ??= $callId === null ? null : (int) $callId;

        if ($data['kind'] === MangoEvent::RECORDING) {
            $this->applyRecording($recording, $data);
        } else {
            $this->applyAdded($recording, $now);
        }

        $recording->save();

        if ($recording->call_id !== null && $recording->wasChanged(['state', 'call_id'])) {
            $this->realtime->recordingChanged($recording->call_id);
        }
    }

    /**
     * Started / Continued / Completed of one recording. A lower seq is an older event:
     * its leg does not replace the newer one. «Completed» with code 1000 into the cloud
     * means Mango keeps the recording; another code or the e-mail only recipient means it
     * does not. A recording Mango reported as added (3.1.8) stays recorded.
     *
     * @param  RecordingEvent  $data
     */
    private function applyRecording(CallRecording $recording, array $data): void
    {
        if ($data['seq'] > $recording->last_seq) {
            $recording->fill([
                'last_seq' => $data['seq'],
                'mango_call_id' => $data['call_id'],
                'recording_state' => $data['recording_state'],
                'extension' => $data['extension'] ?? $recording->extension,
            ]);
        }

        if ($data['recording_state'] !== 'Completed') {
            return;
        }

        $recording->completion_code = $data['completion_code'];
        $recording->recipient = $data['recipient'];

        if ($recording->added_at !== null || $recording->state !== CallRecordingState::Pending) {
            return;
        }

        $code = $data['completion_code'];

        if (($code !== null && $code !== MangoEventContract::RECORDING_SUCCESS) || $data['recipient'] === MangoEventContract::RECIPIENT_MAIL) {
            $recording->state = CallRecordingState::Unavailable;
            $recording->error_code = $data['recipient'] === MangoEventContract::RECIPIENT_MAIL && ($code === null || $code === MangoEventContract::RECORDING_SUCCESS)
                ? 'mail_only'
                : 'not_saved_by_mango';

            return;
        }

        $recording->state = CallRecordingState::Recorded;
    }

    /**
     * The recording is in the cloud storage (3.1.8), even if «Completed» said otherwise.
     */
    private function applyAdded(CallRecording $recording, CarbonImmutable $now): void
    {
        $recording->added_at ??= $now;
        $recording->state = CallRecordingState::Recorded;
        $recording->error_code = null;
    }
}
