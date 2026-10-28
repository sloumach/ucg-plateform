<?php

namespace App\Modules\Reporting\Presentation\Console;

use App\Modules\Reporting\Application\Services\DataPreparationService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Throwable;

final class AuditDataPreparationCommand extends Command
{
    protected $signature = 'data:audit {manifest : Manifeste JSON local explicite} {--organization= : UUID attendu, vérifié sur chaque source}';

    protected $description = 'DATA-001 : auditer des fichiers locaux sans import, écriture métier ou comparaison avec la base.';

    public function handle(DataPreparationService $service, LoggerInterface $logger): int
    {
        try {
            $path = $this->argument('manifest');
            $organization = $this->option('organization');
            if (! is_string($organization)) {
                $this->error(__('data_audit.invalid_manifest'));

                return self::FAILURE;
            }
            $report = $service->audit($path, $organization);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $report->data['status'] === 'blocked' ? 2 : self::SUCCESS;
        } catch (ValidationException) {
            $this->error(__('data_audit.invalid_manifest'));
        } catch (Throwable $exception) {
            // Keep source values, exception messages and local paths out of logs and console output.
            $logger->error('data.audit.failed', ['exception_type' => $exception::class]);
            $this->error(__('data_audit.failed'));
        }

        return self::FAILURE;
    }
}
