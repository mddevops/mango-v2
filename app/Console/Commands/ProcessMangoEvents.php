<?php

namespace App\Console\Commands;

use App\Services\MangoEventProcessor;
use Illuminate\Console\Command;

class ProcessMangoEvents extends Command
{
    protected $signature = 'mango-events:process
        {--limit=100 : Сколько событий обработать за один запуск (1–1000)}
        {--older-than=60 : Брать события, принятые не меньше этого числа секунд назад}';

    protected $description = 'Обработать сохранённые события Mango, которые не обработала очередь (восстановление после сбоя постановки или работы очереди)';

    public function handle(MangoEventProcessor $processor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $olderThan = filter_var($this->option('older-than'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 86400]]);

        if ($limit === false || $olderThan === false) {
            $this->error('Параметр --limit — целое число от 1 до 1000, --older-than — от 0 до 86400.');

            return self::FAILURE;
        }

        $summary = $processor->processPending($limit, $olderThan);

        $this->info("Обработано: {$summary['processed']}; не обработано (ошибка сохранена у события): {$summary['failed']}.");

        return self::SUCCESS;
    }
}
