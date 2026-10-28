<?php

namespace App\Modules\Reporting\Presentation\Console;

use App\Modules\Reporting\Application\Services\DataRehearsalService;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

final class RehearseDataCommand extends Command
{
    protected $signature = 'data:rehearse';

    protected $description = 'DATA-002 : exécuter la reprise fictive isolée et vérifier son retour arrière.';

    public function handle(DataRehearsalService $service, LoggerInterface $logger): int
    {
        try {
            $report = $service->run();
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $report['status'] === 'fictitious_rehearsal_passed' ? self::SUCCESS : 2;
        } catch (Throwable $exception) {
            $logger->error('data.rehearsal.failed', ['exception_type' => $exception::class]);
            $this->error('La reprise à blanc n’a pas pu être vérifiée. Aucun import réel autorisé.');

            return self::FAILURE;
        }
    }
}
