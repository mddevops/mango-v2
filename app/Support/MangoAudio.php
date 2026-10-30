<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * An opened answer of the Mango file service: the status and the checked headers for the
 * browser, the first bytes already read for the content check and the rest of the
 * transport stream. The audio passes through in pieces; nothing is kept in memory or on
 * disk, and the upstream connection is closed when the browser goes away.
 */
final class MangoAudio
{
    private const CHUNK = 65536;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        private readonly ?StreamInterface $body = null,
        private readonly string $head = '',
    ) {}

    public function hasBody(): bool
    {
        return $this->body !== null;
    }

    public function send(): void
    {
        ignore_user_abort(true);

        try {
            echo $this->head;
            flush();

            while ($this->body !== null && ! connection_aborted() && ! $this->body->eof()) {
                $chunk = $this->body->read(self::CHUNK);

                if ($chunk === '') {
                    break;
                }

                echo $chunk;
                flush();
            }
        } catch (Throwable $exception) {
            Log::warning('Mango recording stream was interrupted.', ['exception' => $exception::class]);
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        $this->body?->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}
