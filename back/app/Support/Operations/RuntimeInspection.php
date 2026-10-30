<?php

namespace App\Support\Operations;

final readonly class RuntimeInspection
{
    /** @param array<string, RuntimeCheck> $checks */
    public function __construct(public array $checks, public bool $requested) {}

    public function exitCode(): int
    {
        foreach ($this->checks as $check) {
            if (in_array($check->state, [ProbeState::Failed, ProbeState::Warning], true)) {
                return 1;
            }
        }

        foreach ($this->checks as $check) {
            if ($check->state === ProbeState::Unverified) {
                return 2;
            }
        }

        return 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => match ($this->exitCode()) {
                0 => 'probes_passed', 1 => 'attention_required', default => 'unverified',
            },
            'probe_requested' => $this->requested,
            'scope' => 'partial_read_checks',
            'supervision_validated' => false,
            'alert_dispatched' => false,
            'checks' => array_map(fn (RuntimeCheck $check): array => $check->toArray(), $this->checks),
            'not_verified' => ['worker_liveness', 'scheduler_liveness', 'mail_delivery', 'realtime_connections', 'scanner', 'http_latency', 'backup_restore', 'alert_delivery', 'storage_write_acl_capacity'],
        ];
    }
}
