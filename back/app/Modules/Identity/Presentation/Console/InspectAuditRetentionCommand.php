<?php

namespace App\Modules\Identity\Presentation\Console;

use App\Support\Audit\AuditRetentionPolicy;
use Illuminate\Console\Command;

final class InspectAuditRetentionCommand extends Command
{
    protected $signature = 'audit:retention';

    protected $description = 'Afficher en lecture seule la politique de conservation du journal.';

    public function handle(AuditRetentionPolicy $policy): int
    {
        $policy->description();
        $this->info(__('audit.retention'));

        return self::SUCCESS;
    }
}
