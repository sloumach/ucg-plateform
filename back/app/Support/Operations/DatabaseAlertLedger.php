<?php

namespace App\Support\Operations;

use App\Contracts\Operations\AlertLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use stdClass;

final readonly class DatabaseAlertLedger implements AlertLedger
{
    public function __construct(private DatabaseManager $database) {}

    public function synchronize(string $namespace, RuntimeInspection $inspection, int $escalationSeconds): void
    {
        $checks = $inspection->checks;
        if (count($checks) > 64) {
            throw new LogicException('ALERT_REPORT_INVALID');
        }
        foreach ($checks as $component => $check) {
            if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $component) !== 1
                || preg_match('/\A[A-Z][A-Z0-9_]{0,63}\z/', $check->code) !== 1) {
                throw new LogicException('ALERT_REPORT_INVALID');
            }
        }
        ksort($checks);
        $this->database->connection()->transaction(function () use ($namespace, $checks, $escalationSeconds): void {
            foreach ($checks as $component => $check) {
                if ($check->state === ProbeState::Unverified) {
                    continue;
                }
                $this->observe($namespace, $component, $check, $escalationSeconds);
            }
        });
    }

    private function observe(string $namespace, string $component, RuntimeCheck $check, int $escalationSeconds): void
    {
        $id = hash('sha256', $namespace.':'.$component);
        $scope = hash('sha256', $namespace);
        $active = $check->state !== ProbeState::Passed;
        $severity = $check->state === ProbeState::Failed ? 'error' : 'warning';
        $now = now()->toDateTimeString();
        $table = $this->database->connection()->table('operations_alerts');
        $created = $active && $table->insertOrIgnore([
            'id' => $id, 'scope' => $scope, 'component' => $component, 'code' => $check->code,
            'severity' => $severity, 'status' => 'open', 'version' => 1, 'observations' => 1,
            'first_seen_at' => $now, 'last_seen_at' => $now,
        ]) === 1;
        $row = $table->where('id', $id)->lockForUpdate()->first();
        if ($row === null) {
            return;
        }
        if ($created) {
            $this->event($id, 'opened', 1);

            return;
        }
        if (! $active) {
            if ($row->status !== 'resolved') {
                $version = (int) $row->version + 1;
                $table->where('id', $id)->update(['status' => 'resolved', 'resolved_at' => $now, 'last_seen_at' => $now, 'version' => $version]);
                $this->event($id, 'resolved', $version);
            }

            return;
        }
        $changes = ['last_seen_at' => $now, 'observations' => (int) $row->observations + 1];
        $changed = $row->code !== $check->code || $row->severity !== $severity;
        if ($row->status === 'resolved' || $changed) {
            $version = (int) $row->version + 1;
            $changes = [...$changes, 'code' => $check->code, 'severity' => $severity, 'status' => 'open',
                'version' => $version, 'first_seen_at' => $now, 'resolved_at' => null, 'escalated_at' => null, 'observations' => 1];
            $table->where('id', $id)->update($changes);
            $this->event($id, $row->status === 'resolved' ? 'reopened' : 'changed', $version);

            return;
        }
        $age = now()->getTimestamp() - CarbonImmutable::parse($row->first_seen_at, 'UTC')->getTimestamp();
        if ($row->status === 'open' && $row->escalated_at === null && $age >= $escalationSeconds) {
            $version = (int) $row->version + 1;
            $changes = [...$changes, 'escalated_at' => $now, 'version' => $version];
            $this->event($id, 'escalated', $version);
        }
        $table->where('id', $id)->update($changes);
    }

    /** @return list<array<string, mixed>> */
    public function listing(string $namespace, int $page): array
    {
        return array_values($this->database->connection()->table('operations_alerts')->where('scope', hash('sha256', $namespace))
            ->orderByDesc('last_seen_at')->orderBy('id')->offset(($page - 1) * 50)->limit(50)
            ->get(['id', 'component', 'code', 'severity', 'status', 'version', 'observations', 'first_seen_at',
                'last_seen_at', 'escalated_at', 'resolved_at'])
            ->map(static fn (stdClass $row): array => get_object_vars($row))->all());
    }

    public function acknowledge(string $namespace, string $id, int $version, string $incident): void
    {
        $this->database->connection()->transaction(function () use ($namespace, $id, $version, $incident): void {
            $query = $this->database->connection()->table('operations_alerts')->where('scope', hash('sha256', $namespace))->where('id', $id);
            $row = $query->lockForUpdate()->first();
            if ($row === null) {
                throw new LogicException('ALERT_NOT_FOUND');
            }
            if ((int) $row->version !== $version) {
                throw new LogicException('ALERT_VERSION_CONFLICT');
            }
            if ($row->status !== 'open') {
                throw new LogicException('ALERT_NOT_OPEN');
            }
            $query->update(['status' => 'acknowledged', 'version' => $version + 1]);
            $this->event($id, 'acknowledged', $version + 1, $incident);
        });
    }

    private function event(string $id, string $action, int $version, ?string $incident = null): void
    {
        $this->database->connection()->table('operations_alert_events')->insert([
            'alert_id' => $id, 'action' => $action, 'version' => $version,
            'incident_id' => $incident, 'created_at' => now(),
        ]);
    }
}
