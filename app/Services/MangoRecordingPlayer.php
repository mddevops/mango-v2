<?php

namespace App\Services;

use App\Exceptions\MangoPlaybackFailure;
use App\Models\CallRecording;
use App\Models\IntegrationConnection;
use App\Support\MangoAudio;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Plays a Mango recording without keeping it (MangoOffice_VPBX_API v1.9, 3.5.2):
 *
 * 1. a fresh link `GET {api}/queries/recording/link/{recording_id}/play/{vpbx_api_key}/
 *    {timestamp}/{sign}`, `sign = sha256(vpbx_api_key + timestamp + recording_id +
 *    vpbx_api_salt)`, `timestamp` — the Unix time until which the link is valid;
 * 2. Mango answers 302 to a one-time link of the file service; only `https` links of the
 *    configured file hosts are accepted, the link is never stored, logged or followed
 *    twice, and neither the key nor the signature goes there;
 * 3. the file link is requested once, with the browser's validated Range if any, and the
 *    answer is passed through as a stream.
 *
 * Every browser request repeats all steps: a one-time link is never reused. Requests use
 * Guzzle's stream handler (`stream`), whose body is the network stream itself — without
 * it the body would be buffered in `php://temp`, which spills to a temporary file; the
 * handler needs `allow_url_fopen`, so playback refuses to start without it. No database
 * transaction is open here.
 */
class MangoRecordingPlayer
{
    /** API result codes (Список кодов результатов): 3320 — «Запись разговора не найдена». */
    private const RESULT_NOT_FOUND = 3320;

    /** Bytes checked to tell an HTML page or a JSON answer from audio. */
    private const SNIFF = 64;

    /** Bytes of a non-audio answer read to find the API result code. */
    private const ANSWER = 4096;

    /**
     * The 3.5.2 link valid until `$expires` (Unix time).
     */
    public function link(string $key, string $salt, string $recordingId, int $expires): string
    {
        $sign = hash('sha256', $key.$expires.$recordingId.$salt);

        return rtrim((string) config('services.mango.api_url'), '/')
            .'/queries/recording/link/'.rawurlencode($recordingId).'/play/'.rawurlencode($key).'/'.$expires.'/'.$sign;
    }

    /**
     * The browser's Range when it is one well-formed byte range; anything else is not
     * forwarded (RFC 9110 lets a server ignore it) and the whole file is requested.
     */
    public static function range(?string $header): ?string
    {
        $value = trim((string) $header);

        if (preg_match('/^bytes=(?:(\d{1,15})-(\d{0,15})|-(\d{1,15}))$/', $value, $match) !== 1) {
            return null;
        }

        if (($match[2] ?? '') !== '' && (int) $match[2] < (int) $match[1]) {
            return null;
        }

        return $value;
    }

    /**
     * Whether Mango gives a file link for the recording now. The link is not followed, so
     * nothing is played and no later request depends on it.
     */
    public function check(CallRecording $recording): void
    {
        $this->fileLink($recording);
    }

    /**
     * Opens the audio stream; the caller sends or closes it.
     */
    public function open(CallRecording $recording, ?string $range): MangoAudio
    {
        $response = $this->request($this->fileLink($recording), $range === null ? [] : ['Range' => $range]);
        $body = $response->toPsrResponse()->getBody();

        try {
            return $this->audio($response, $body);
        } catch (Throwable $exception) {
            $body->close();

            throw $exception;
        }
    }

    private function fileLink(CallRecording $recording): string
    {
        if (! (bool) ini_get('allow_url_fopen')) {
            throw new MangoPlaybackFailure('stream_unsupported');
        }

        $connection = IntegrationConnection::query()->find($recording->integration_connection_id);
        $key = (string) ($connection?->secrets['api_key'] ?? '');
        $salt = (string) ($connection?->secrets['api_salt'] ?? '');

        if ($connection === null || ! $connection->active || $key === '' || $salt === '') {
            throw new MangoPlaybackFailure('connection_unavailable');
        }

        $expires = CarbonImmutable::now()->getTimestamp() + max(1, (int) config('services.mango.recordings.link_ttl'));
        $response = $this->request($this->link($key, $salt, $recording->recording_id, $expires));
        $body = $response->toPsrResponse()->getBody();

        try {
            $status = $response->status();

            if ($status >= 300 && $status < 400) {
                $location = $response->header('Location');

                if (! $this->allowedFileUrl($location)) {
                    throw new MangoPlaybackFailure('redirect_not_allowed', false, ['http_status' => $status]);
                }

                return $location;
            }

            throw $this->rejection($status, $this->resultCode($body));
        } finally {
            $body->close();
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(string $url, array $headers = []): Response
    {
        try {
            return Http::withoutRedirecting()
                ->withOptions([
                    'stream' => true,
                    'decode_content' => false,
                    'read_timeout' => $this->setting('request_timeout'),
                ])
                ->withHeaders(['Accept-Encoding' => 'identity', ...$headers])
                ->connectTimeout($this->setting('connect_timeout'))
                ->timeout($this->setting('request_timeout'))
                ->get($url);
        } catch (ConnectionException) {
            // The message of a connection error holds the address: it is dropped.
            throw new MangoPlaybackFailure('mango_unreachable');
        }
    }

    /**
     * Checks the answer of the file service: 200 or 206 audio, or 416 without a body.
     * A 200 answer to a Range request stays 200: the range is not faked.
     */
    private function audio(Response $response, StreamInterface $body): MangoAudio
    {
        $status = $response->status();

        if ($status === 416) {
            $body->close();
            $contentRange = $response->header('Content-Range');

            return new MangoAudio(416, preg_match('#^bytes \*/\d+$#', $contentRange) === 1 ? ['Content-Range' => $contentRange] : []);
        }

        if ($status !== 200 && $status !== 206) {
            throw match (true) {
                $status >= 300 && $status < 400 => new MangoPlaybackFailure('redirect_not_allowed', false, ['http_status' => $status]),
                $status === 404 || $status === 410 => new MangoPlaybackFailure('recording_not_found', true, ['http_status' => $status]),
                $status === 429 || $status >= 500 => new MangoPlaybackFailure('mango_unavailable', false, ['http_status' => $status]),
                default => new MangoPlaybackFailure('unexpected_response', false, ['http_status' => $status]),
            };
        }

        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

        if (! in_array($type, (array) config('services.mango.recordings.types'), true)) {
            throw new MangoPlaybackFailure('not_audio', false, ['http_status' => $status]);
        }

        $headers = ['Content-Type' => 'audio/mpeg'];
        $length = $response->header('Content-Length');

        if (ctype_digit($length)) {
            $headers['Content-Length'] = $length;
        }

        $fromStart = true;

        if ($status === 206) {
            $contentRange = $response->header('Content-Range');

            if (preg_match('#^bytes (\d+)-(\d+)/(\d+|\*)$#', $contentRange, $match) !== 1) {
                throw new MangoPlaybackFailure('unexpected_response', false, ['http_status' => $status]);
            }

            $headers['Content-Range'] = $contentRange;
            $fromStart = $match[1] === '0';
        }

        $headers['Accept-Ranges'] = $status === 206 || strtolower(trim($response->header('Accept-Ranges'))) === 'bytes' ? 'bytes' : 'none';

        $head = '';

        while (strlen($head) < self::SNIFF && ! $body->eof()) {
            $chunk = $body->read(self::SNIFF - strlen($head));

            if ($chunk === '') {
                break;
            }

            $head .= $chunk;
        }

        if ($head === '' || ($fromStart && $this->looksLikeText($head))) {
            throw new MangoPlaybackFailure('not_audio', false, ['http_status' => $status]);
        }

        return new MangoAudio($status, $headers, $body, $head);
    }

    /**
     * An HTML page or a JSON answer is not audio, whatever the header says.
     */
    private function looksLikeText(string $head): bool
    {
        $start = ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head);

        return $start !== '' && in_array($start[0], ['<', '{', '['], true);
    }

    /**
     * Documented answers other than the redirect: the API result code first (2.1.1,
     * «Список кодов результатов»), the HTTP status only without one.
     */
    private function rejection(int $status, ?int $result): MangoPlaybackFailure
    {
        $diagnostics = array_filter(['http_status' => $status, 'mango_result' => $result], fn ($value) => $value !== null);

        return match (true) {
            $result === self::RESULT_NOT_FOUND => new MangoPlaybackFailure('recording_not_found', true, $diagnostics),
            $result !== null && $result >= 2000 && $result < 3000 => new MangoPlaybackFailure('mango_account_restricted', false, $diagnostics),
            $result !== null && $result >= 3000 && $result < 4000 => new MangoPlaybackFailure('mango_rejected', false, $diagnostics),
            $result !== null && $result >= 5000 && $result < 6000 => new MangoPlaybackFailure('mango_unavailable', false, $diagnostics),
            $result === null && ($status === 429 || $status >= 500) => new MangoPlaybackFailure('mango_unavailable', false, $diagnostics),
            $result === null && $status === 404 => new MangoPlaybackFailure('recording_not_found', true, $diagnostics),
            default => new MangoPlaybackFailure('unexpected_response', false, $diagnostics),
        };
    }

    private function resultCode(StreamInterface $body): ?int
    {
        try {
            $data = json_decode($body->read(self::ANSWER), true);
        } catch (Throwable) {
            return null;
        }

        $result = is_array($data) ? ($data['result'] ?? null) : null;

        return is_int($result) || (is_string($result) && ctype_digit($result)) ? (int) $result : null;
    }

    private function allowedFileUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048) {
            return false;
        }

        $parts = parse_url($url);
        $hosts = array_map('strtolower', (array) config('services.mango.recordings.file_hosts'));

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ($parts['port'] ?? 443) === 443
            && in_array(strtolower($parts['host']), $hosts, true);
    }

    private function setting(string $key): int
    {
        return (int) config("services.mango.recordings.{$key}");
    }
}
