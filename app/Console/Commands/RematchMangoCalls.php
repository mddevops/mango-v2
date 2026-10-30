<?php

namespace App\Console\Commands;

use App\Models\Call;
use App\Services\MangoCallService;
use Illuminate\Console\Command;
use Throwable;

class RematchMangoCalls extends Command
{
    protected $signature = 'mango-calls:rematch
        {--connection= : id подключения Mango (обязательно, если не указан --call)}
        {--call= : Сопоставить только звонок с этим id}
        {--limit=100 : Сколько звонков, требующих разбора, обработать (1–1000)}';

    protected $description = 'Повторить сопоставление звонков Mango, требующих разбора (линия, источник, салон, заявка, ответивший), после исправления настроек';

    public function handle(MangoCallService $calls): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $connection = filter_var($this->option('connection'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $call = filter_var($this->option('call'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($limit === false || ($connection === false && $call === false)) {
            $this->error('Укажите --connection=<id подключения> или --call=<id звонка>; --limit — целое число от 1 до 1000.');

            return self::FAILURE;
        }

        $targets = Call::query()
            ->when($call !== false, fn ($query) => $query->whereKey($call), fn ($query) => $query->where('integration_connection_id', $connection)->where('needs_review', true))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $summary = ['resolved' => 0, 'review' => 0, 'failed' => 0];

        foreach ($targets as $target) {
            try {
                $summary[$calls->rematch($target, null)->needs_review ? 'review' : 'resolved']++;
            } catch (Throwable $exception) {
                report($exception);
                $summary['failed']++;
            }
        }

        $this->info("Сопоставлено: {$summary['resolved']}; всё ещё требуют разбора: {$summary['review']}; ошибок: {$summary['failed']}.");

        return self::SUCCESS;
    }
}
