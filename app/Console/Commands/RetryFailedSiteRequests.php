<?php

namespace App\Console\Commands;

use App\Services\SiteRequestProcessor;
use Illuminate\Console\Command;

class RetryFailedSiteRequests extends Command
{
    protected $signature = 'site-requests:retry-failed
        {--limit=100 : Сколько обращений повторить за один запуск (1–1000)}
        {--receipt= : Повторить только обращение с этим receipt_id}';

    protected $description = 'Повторить обработку обращений с сайта в состоянии «Ошибка» с новым набором попыток';

    public function handle(SiteRequestProcessor $processor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $receipt = $this->option('receipt');

        if ($limit === false) {
            $this->error('Параметр --limit должен быть целым числом от 1 до 1000.');

            return self::FAILURE;
        }

        $summary = $processor->retryFailed($limit, is_string($receipt) && $receipt !== '' ? $receipt : null);

        $this->info("Обработано: {$summary['processed']}; снова с ошибкой: {$summary['failed']}.");

        return self::SUCCESS;
    }
}
