<?php

namespace App\Console\Commands;

use App\Support\Operations\AlertService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use LogicException;
use Throwable;

#[Signature('ops:alerts {action=list} {id?} {--probe} {--page=1} {--expected-version=} {--incident=} {--confirm} {--json}')]
#[Description('Lister ou acquitter une alerte technique ; accès serveur, aucune notification')]
class ManageOperationsAlerts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AlertService $alerts, Repository $config): int
    {
        if ($config->get('operations.alerts.enabled') !== true) {
            $this->line('{"code":"ALERT_LEDGER_DISABLED","alert_dispatched":false}');

            return 2;
        }
        $action = $this->argument('action');
        try {
            if ($action === 'list' && $this->option('probe')) {
                $page = filter_var($this->option('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
                if (! is_int($page)) {
                    throw new LogicException('ALERT_PAGE_INVALID');
                }
                $report = ['page' => $page, 'page_size' => 50, 'alerts' => $alerts->listing($page), 'alert_dispatched' => false];
            } elseif ($action === 'acknowledge' && $this->option('confirm')) {
                $version = filter_var($this->option('expected-version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483646]]);
                $id = $this->argument('id');
                $incident = $this->option('incident');
                if (! is_int($version) || ! is_string($id) || ! is_string($incident)) {
                    throw new LogicException('ALERT_ACKNOWLEDGEMENT_INVALID');
                }
                $alerts->acknowledge($id, $version, $incident);
                $report = ['code' => 'ALERT_ACKNOWLEDGED', 'alert_dispatched' => false];
            } else {
                $this->line('{"code":"ALERT_ACTION_REQUIRES_EXPLICIT_OPTION","alert_dispatched":false}');

                return 2;
            }
        } catch (Throwable $exception) {
            $allowed = ['ALERT_NOT_FOUND', 'ALERT_NOT_OPEN', 'ALERT_VERSION_CONFLICT', 'ALERT_PAGE_INVALID', 'ALERT_ACKNOWLEDGEMENT_INVALID'];
            $code = $exception instanceof LogicException && in_array($exception->getMessage(), $allowed, true)
                ? $exception->getMessage() : 'ALERT_ACTION_FAILED';
            $this->line(json_encode(['code' => $code, 'alert_dispatched' => false], JSON_THROW_ON_ERROR));

            return 1;
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return 0;
    }
}
