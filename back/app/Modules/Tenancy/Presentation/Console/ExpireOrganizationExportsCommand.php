<?php

namespace App\Modules\Tenancy\Presentation\Console;

use App\Modules\Tenancy\Application\Services\OrganizationPortabilityService;
use Illuminate\Console\Command;
use Throwable;

final class ExpireOrganizationExportsCommand extends Command
{
    protected $signature = 'organizations:expire-exports';

    protected $description = 'Nettoyer un lot borné d’archives expirées, sans supprimer les données métier.';

    public function handle(OrganizationPortabilityService $exports): int
    {
        try {
            if ($exports->expireBatch() === 0) {
                $this->info(__('tenancy.portability.cleanup_done'));

                return self::SUCCESS;
            }
        } catch (Throwable $exception) {
            report($exception);
        }
        $this->error(__('tenancy.portability.failed'));

        return self::FAILURE;
    }
}
