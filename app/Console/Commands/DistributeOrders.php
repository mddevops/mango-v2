<?php

namespace App\Console\Commands;

use App\Services\CallbackDistributor;
use Illuminate\Console\Command;

class DistributeOrders extends Command
{
    protected $signature = 'orders:distribute
        {--limit=50 : Сколько ожидающих заявок обработать за один запуск (1–500)}';

    protected $description = 'Повторить распределение ожидающих заявок обратного звонка одним небольшим пакетом';

    public function handle(CallbackDistributor $distributor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);

        if ($limit === false) {
            $this->error('Параметр --limit должен быть целым числом от 1 до 500.');

            return self::FAILURE;
        }

        $summary = $distributor->retryPending($limit);

        $this->info("Обработано: {$summary['processed']}; назначено: {$summary['assigned']}; без кандидатов: {$summary['waiting']}; период закончился: {$summary['expired']}.");

        return self::SUCCESS;
    }
}
