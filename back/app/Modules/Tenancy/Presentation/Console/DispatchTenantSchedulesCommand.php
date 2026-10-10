<?php

namespace App\Modules\Tenancy\Presentation\Console;

use App\Modules\Tenancy\Application\Contracts\TenantScheduleQueue;
use App\Modules\Tenancy\Application\Services\TenantScheduleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

final class DispatchTenantSchedulesCommand extends Command
{
    protected $signature = 'tenancy:dispatch-scheduled {task : Nom de tâche enregistré côté serveur}';

    protected $description = 'Distribuer une tâche système aux organisations actives par pages bornées';

    /**
     * Execute the console command.
     */
    public function handle(TenantScheduleRegistry $registry, TenantScheduleQueue $queue): int
    {
        try {
            $task = (string) $this->argument('task');
            $registry->task($task);
            $runId = (string) Str::uuid7();
            $queue->page($task, $runId);
        } catch (Throwable $exception) {
            report($exception);
            $this->error(__('tenancy.schedule.failed'));

            return self::FAILURE;
        }
        $this->info(__('tenancy.schedule.queued', ['run' => $runId]));

        return self::SUCCESS;
    }
}
