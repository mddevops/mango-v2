<?php

namespace App\Console\Commands;

use App\Services\MangoEventProcessor;
use Illuminate\Console\Command;

class RetryFailedMangoEvents extends Command
{
    protected $signature = 'mango-events:retry-failed
        {--limit=100 : Сколько событий повторить за один запуск (1–1000)}
        {--event= : Повторить только событие с этим id}';

    protected $description = 'Повторить обработку событий Mango в состоянии «Ошибка» с новым набором попыток';

    public function handle(MangoEventProcessor $processor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $event = $this->option('event');
        $eventId = $event === null || $event === '' ? null : filter_var($event, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($limit === false || $eventId === false) {
            $this->error('Параметр --limit — целое число от 1 до 1000, --event — id события.');

            return self::FAILURE;
        }

        $summary = $processor->retryFailed($limit, $eventId);

        $this->info("Обработано: {$summary['processed']}; снова с ошибкой: {$summary['failed']}.");

        return self::SUCCESS;
    }
}
