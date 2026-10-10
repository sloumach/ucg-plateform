<?php

namespace App\Modules\Tenancy\Presentation\Console;

use App\Architecture\Database\TenantSchemaInspector;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

final class CheckTenantSchemaCommand extends Command
{
    protected $signature = 'tenancy:check-schema';

    protected $description = 'Vérifier en lecture seule les contraintes du schéma multi-tenant';

    public function handle(TenantSchemaInspector $inspector): int
    {
        try {
            $violations = $inspector->violations();
        } catch (QueryException $exception) {
            report($exception);
            $this->error(__('tenancy.schema.unavailable'));

            return self::FAILURE;
        }
        if ($violations !== []) {
            foreach ($violations as $violation) {
                $this->error($violation);
            }

            return self::FAILURE;
        }

        $this->info(__('tenancy.schema.valid'));

        return self::SUCCESS;
    }
}
