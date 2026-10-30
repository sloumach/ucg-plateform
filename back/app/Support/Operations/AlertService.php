<?php

namespace App\Support\Operations;

use App\Contracts\Operations\AlertLedger;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use LogicException;

final readonly class AlertService
{
    public function __construct(private AlertLedger $ledger, private MonitoringScope $scope, private Repository $config) {}

    public function synchronize(RuntimeInspection $inspection): void
    {
        $this->assertEnabled();
        if (! $inspection->requested) {
            throw new LogicException('ALERT_PROBE_REQUIRED');
        }
        $this->ledger->synchronize($this->scope->namespace(), $inspection,
            $this->scope->integer('alerts.escalation_seconds', 60, 604800));
    }

    /** @return list<array<string, mixed>> */
    public function listing(int $page): array
    {
        $this->assertEnabled();
        if ($page < 1 || $page > 100000) {
            throw new LogicException('ALERT_PAGE_INVALID');
        }

        return $this->ledger->listing($this->scope->namespace(), $page);
    }

    public function acknowledge(string $id, int $version, string $incident): void
    {
        $this->assertEnabled();
        if (preg_match('/\A[a-f0-9]{64}\z/', $id) !== 1 || $version < 1 || ! Str::isUuid($incident)) {
            throw new LogicException('ALERT_ACKNOWLEDGEMENT_INVALID');
        }
        $this->ledger->acknowledge($this->scope->namespace(), $id, $version, $incident);
    }

    private function assertEnabled(): void
    {
        if ($this->config->get('operations.alerts.enabled') !== true) {
            throw new LogicException('ALERT_LEDGER_DISABLED');
        }
    }
}
