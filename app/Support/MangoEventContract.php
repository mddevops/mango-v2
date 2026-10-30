<?php

namespace App\Support;

use App\Models\IntegrationConnection;
use App\Models\MangoEvent;
use Carbon\CarbonImmutable;

/**
 * Incoming Mango VPBX events (MangoOffice_VPBX_API v1.9, «Версия от 31.08.2026»).
 *
 * - The body has three fields `vpbx_api_key`, `sign`, `json` (2.2, 2.4.3); `json` is a
 *   string. `sign = sha256(vpbx_api_key + json + vpbx_api_salt)` over the raw string
 *   exactly as received (2.4.3), checked before the JSON is parsed.
 * - Numbers come both as numbers and as strings in the documented examples (`"seq": "1"`,
 *   `"timestamp": "1399906976"`), so both are accepted.
 * - Times are Unix timestamps: an absolute moment, read without any shift. The «UTC+3»
 *   in the field descriptions names Moscow time of the platform, not an offset added to
 *   the number (3.1.2, 3.1.6; statistics 3.4 calls the same fields «unix время»).
 *
 * @phpstan-type Party array{extension: string|null, number: string|null}
 * @phpstan-type CallEvent array{kind: 'call', entry_id: string, call_id: string, seq: int, timestamp: int, call_state: string, location: string|null, from: array{extension: string|null, number: string|null, taken_from_call_id: string|null}, to: array{extension: string|null, number: string|null, line_number: string|null}, disconnect_reason: int|null, technical: bool}
 * @phpstan-type SummaryEvent array{kind: 'summary', entry_id: string, call_direction: int, from: Party, to: Party, line_number: string|null, create_time: int, talk_time: int, end_time: int, entry_result: int, disconnect_reason: int|null}
 * @phpstan-type RecordingEvent array{kind: 'recording', recording_id: string, recording_state: string, seq: int, entry_id: string, call_id: string, extension: string|null, timestamp: int, completion_code: int|null, recipient: string|null}
 * @phpstan-type RecordAddedEvent array{kind: 'record_added', recording_id: string, entry_id: string, timestamp: int}
 */
final class MangoEventContract
{
    public const MAX_JSON_BYTES = 65536;

    /** Paths handled now: event path => kind. */
    public const HANDLED = [
        'call' => MangoEvent::CALL,
        'summary' => MangoEvent::SUMMARY,
        'recording' => MangoEvent::RECORDING,
        'record/added' => MangoEvent::RECORD_ADDED,
    ];

    /**
     * Documented paths this stage does not use (topics, SMS, DTMF): the signature is
     * checked and the answer is 200, nothing is saved.
     */
    public const IGNORED = ['record/tagged', 'sms', 'dtmf'];

    public const CALL_STATES = ['Appeared', 'Connected', 'OnHold', 'Disconnected'];

    /** `recording_state` of 3.1.4. */
    public const RECORDING_STATES = ['Started', 'Continued', 'Completed'];

    /** `completion_code` of a successful recording (3.1.4, «Результаты записи разговора»). */
    public const RECORDING_SUCCESS = 1000;

    /** `recipient` value of a recording sent by e-mail only (3.1.4). */
    public const RECIPIENT_MAIL = 'Mail';

    public static function signatureValid(IntegrationConnection $connection, string $apiKey, string $json, string $sign): bool
    {
        $key = (string) ($connection->secrets['api_key'] ?? '');
        $salt = (string) ($connection->secrets['api_salt'] ?? '');

        if ($key === '' || $salt === '' || ! hash_equals($key, $apiKey)) {
            return false;
        }

        return hash_equals(hash('sha256', $apiKey.$json.$salt), $sign);
    }

    /**
     * The validated event or null when the structure does not match the contract.
     *
     * @return CallEvent|SummaryEvent|RecordingEvent|RecordAddedEvent|null
     */
    public static function parse(string $kind, string $json): ?array
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            return null;
        }

        return match ($kind) {
            MangoEvent::CALL => self::callEvent($data),
            MangoEvent::SUMMARY => self::summaryEvent($data),
            MangoEvent::RECORDING => self::recordingEvent($data),
            MangoEvent::RECORD_ADDED => self::recordAddedEvent($data),
            default => null,
        };
    }

    /**
     * A repeated delivery of the same event: the same leg and seq for `/events/call`, the
     * same entry for the final `/events/summary`, the same recording and seq for
     * `/events/recording`, the same recording for `/events/record/added`. Mango sends no
     * id of a delivery.
     *
     * @param  CallEvent|SummaryEvent|RecordingEvent|RecordAddedEvent  $event
     */
    public static function dedupKey(array $event): string
    {
        return hash('sha256', match ($event['kind']) {
            MangoEvent::CALL => implode("\n", [MangoEvent::CALL, $event['entry_id'], $event['call_id'], $event['seq']]),
            MangoEvent::SUMMARY => implode("\n", [MangoEvent::SUMMARY, $event['entry_id']]),
            MangoEvent::RECORDING => implode("\n", [MangoEvent::RECORDING, $event['recording_id'], $event['seq']]),
            MangoEvent::RECORD_ADDED => implode("\n", [MangoEvent::RECORD_ADDED, $event['recording_id']]),
        });
    }

    /**
     * @param  CallEvent|SummaryEvent|RecordingEvent|RecordAddedEvent  $event
     */
    public static function occurredAt(array $event): ?CarbonImmutable
    {
        $timestamp = $event['kind'] === MangoEvent::SUMMARY ? $event['end_time'] : $event['timestamp'];

        return self::moment($timestamp);
    }

    /**
     * 3.1.4: recording_id (up to 128 bytes), recording_state, seq, entry_id, call_id and
     * timestamp are required; extension, completion_code, recipient and command_id are not.
     *
     * @param  array<mixed>  $data
     * @return RecordingEvent|null
     */
    private static function recordingEvent(array $data): ?array
    {
        $recordingId = self::id($data['recording_id'] ?? null);
        $state = $data['recording_state'] ?? null;
        $seq = self::integer($data['seq'] ?? null);
        $entryId = self::id($data['entry_id'] ?? null);
        $callId = self::id($data['call_id'] ?? null);
        $timestamp = self::integer($data['timestamp'] ?? null);

        if ($recordingId === null || ! in_array($state, self::RECORDING_STATES, true) || $seq === null || $entryId === null || $callId === null || $timestamp === null) {
            return null;
        }

        return [
            'kind' => MangoEvent::RECORDING,
            'recording_id' => $recordingId,
            'recording_state' => $state,
            'seq' => $seq,
            'entry_id' => $entryId,
            'call_id' => $callId,
            'extension' => self::text($data['extension'] ?? null, 32),
            'timestamp' => $timestamp,
            'completion_code' => self::integer($data['completion_code'] ?? null),
            'recipient' => self::text($data['recipient'] ?? null, 16),
        ];
    }

    /**
     * 3.1.8: entry_id, recording_id and timestamp; product_id and user_id (a Mango
     * employee id, not an extension, −1 when unknown) are not used.
     *
     * @param  array<mixed>  $data
     * @return RecordAddedEvent|null
     */
    private static function recordAddedEvent(array $data): ?array
    {
        $recordingId = self::id($data['recording_id'] ?? null);
        $entryId = self::id($data['entry_id'] ?? null);
        $timestamp = self::integer($data['timestamp'] ?? null);

        if ($recordingId === null || $entryId === null || $timestamp === null) {
            return null;
        }

        return ['kind' => MangoEvent::RECORD_ADDED, 'recording_id' => $recordingId, 'entry_id' => $entryId, 'timestamp' => $timestamp];
    }

    public static function moment(int $timestamp): ?CarbonImmutable
    {
        return $timestamp > 0 ? CarbonImmutable::createFromTimestampUTC($timestamp) : null;
    }

    /**
     * SIP addresses and service numbers are not client phones (3.1.2: `number` may be a
     * SIP address); a client phone is a complete Russian number only.
     */
    public static function isSip(?string $number): bool
    {
        return $number !== null && (str_starts_with(strtolower($number), 'sip:') || str_contains($number, '@'));
    }

    /**
     * @param  array<mixed>  $data
     * @return CallEvent|null
     */
    private static function callEvent(array $data): ?array
    {
        $entryId = self::id($data['entry_id'] ?? null);
        $callId = self::id($data['call_id'] ?? null);
        $seq = self::integer($data['seq'] ?? null);
        $timestamp = self::integer($data['timestamp'] ?? null);
        $state = $data['call_state'] ?? null;
        $from = $data['from'] ?? [];
        $to = $data['to'] ?? [];

        if ($entryId === null || $callId === null || $seq === null || $timestamp === null || ! in_array($state, self::CALL_STATES, true) || ! is_array($from) || ! is_array($to)) {
            return null;
        }

        $takenFrom = self::id($from['taken_from_call_id'] ?? null);
        $commandId = self::text($data['command_id'] ?? null);

        return [
            'kind' => MangoEvent::CALL,
            'entry_id' => $entryId,
            'call_id' => $callId,
            'seq' => $seq,
            'timestamp' => $timestamp,
            'call_state' => $state,
            'location' => self::text($data['location'] ?? null, 16),
            'from' => [
                'extension' => self::text($from['extension'] ?? null, 32),
                'number' => self::text($from['number'] ?? null),
                'taken_from_call_id' => $takenFrom,
            ],
            'to' => [
                'extension' => self::text($to['extension'] ?? null, 32),
                'number' => self::text($to['number'] ?? null),
                'line_number' => self::text($to['line_number'] ?? null),
            ],
            'disconnect_reason' => self::integer($data['disconnect_reason'] ?? null),
            // A call started by a command without a previous leg, a Mango callback or a
            // task is not an incoming request of a client (3.1.2, 3.2; behaviour examples).
            'technical' => ($commandId !== null && $takenFrom === null)
                || self::text($data['callback_initiator'] ?? null) !== null
                || self::text($data['task_id'] ?? null) !== null,
        ];
    }

    /**
     * @param  array<mixed>  $data
     * @return SummaryEvent|null
     */
    private static function summaryEvent(array $data): ?array
    {
        $entryId = self::id($data['entry_id'] ?? null);
        $direction = self::integer($data['call_direction'] ?? null);
        $createTime = self::integer($data['create_time'] ?? null);
        $talkTime = self::integer($data['talk_time'] ?? null);
        $endTime = self::integer($data['end_time'] ?? null);
        $result = self::integer($data['entry_result'] ?? null);
        $from = $data['from'] ?? [];
        $to = $data['to'] ?? [];

        if ($entryId === null || ! in_array($direction, [0, 1, 2], true) || $createTime === null || $talkTime === null
            || $endTime === null || ! in_array($result, [0, 1], true) || ! is_array($from) || ! is_array($to)) {
            return null;
        }

        return [
            'kind' => MangoEvent::SUMMARY,
            'entry_id' => $entryId,
            'call_direction' => $direction,
            'from' => ['extension' => self::text($from['extension'] ?? null, 32), 'number' => self::text($from['number'] ?? null)],
            'to' => ['extension' => self::text($to['extension'] ?? null, 32), 'number' => self::text($to['number'] ?? null)],
            'line_number' => self::text($data['line_number'] ?? null),
            'create_time' => $createTime,
            'talk_time' => $talkTime,
            'end_time' => $endTime,
            'entry_result' => $result,
            'disconnect_reason' => self::integer($data['disconnect_reason'] ?? null),
        ];
    }

    private static function id(mixed $value): ?string
    {
        $text = self::text($value);

        return $text !== null && strlen($text) <= 128 ? $text : null;
    }

    private static function text(mixed $value, int $max = 128): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || mb_strlen($value) > $max ? null : $value;
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= 4294967295 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d{1,10}$/', $value) === 1) {
            $number = (int) $value;

            return $number <= 4294967295 ? $number : null;
        }

        return null;
    }
}
