<?php

namespace App\Modules\Tenancy\Presentation\Console;

use App\Modules\Tenancy\Application\Services\TenantArtifactService;
use Illuminate\Console\Command;
use Throwable;

final class ExpireTenantArtifactsCommand extends Command
{
    protected $signature = 'tenancy:expire-artifacts';

    protected $description = 'Supprimer un lot borné de fichiers privés expirés et libérer leur quota.';

    public function handle(TenantArtifactService $artifacts): int
    {
        try {
            if ($artifacts->expireBatch() === 0) {
                $this->info(__('report_exports.cleanup_done'));

                return self::SUCCESS;
            }
        } catch (Throwable $exception) {
            report($exception);
        }
        $this->error(__('report_exports.cleanup_failed'));

        return self::FAILURE;
    }
}
