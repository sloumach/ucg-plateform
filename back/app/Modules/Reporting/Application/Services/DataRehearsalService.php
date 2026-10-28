<?php

namespace App\Modules\Reporting\Application\Services;

use App\Modules\Reporting\Domain\Contracts\DataRehearsalRunner;

final readonly class DataRehearsalService
{
    public const array EXPECTED = [
        'pipeline' => ['people_created' => 2, 'teams_created' => 1, 'matches_created' => 1, 'contracts_created' => 1, 'observations_created' => 2, 'samples_checked' => 4, 'links_checked' => 5],
        'idempotency' => ['people_created' => 2, 'observations_created' => 1, 'duplicates_skipped' => 1],
        'invalid_rows' => ['invalid_rows' => 2, 'conflicts_rejected' => 1, 'observations_created' => 1],
        'tenant_isolation' => ['denied_requests' => 3, 'out_of_tenant_writes' => 0],
        'interruption' => ['partial_rows' => 1, 'interruption_detected' => true],
    ];

    public function __construct(private DataRehearsalRunner $runner) {}

    /** @return array<string,mixed> */
    public function run(): array
    {
        $result = $this->runner->run();
        $checks = [];
        $valid = true;
        foreach ($result['checks'] as $check) {
            $name = $check['check'];
            if (! isset(self::EXPECTED[$name]) || isset($checks[$name])) {
                $valid = false;

                continue;
            }
            $expected = [...self::EXPECTED[$name], 'baseline_restored' => true, 'artifacts_restored' => true];
            $facts = [];
            foreach ($expected as $field => $value) {
                $facts[$field] = $check['facts'][$field] ?? null;
                if ($facts[$field] !== $value) {
                    $valid = false;
                }
            }
            $checks[$name] = $facts;
        }
        $digestValid = preg_match('/^[a-f0-9]{64}$/D', $result['scenario_sha256']) === 1;
        $passed = $valid && $digestValid && count($checks) === count(self::EXPECTED) && $result['exit_code'] === 0
            && $result['tests'] === count(self::EXPECTED) && $result['assertions'] > 0
            && $result['failures'] === 0 && $result['errors'] === 0 && $result['skipped'] === 0;

        return ['version' => 1, 'objective' => 'UCG-DATA-002', 'dataset_kind' => 'fictitious', 'mode' => 'isolated_rehearsal',
            'status' => $passed ? 'fictitious_rehearsal_passed' : 'rehearsal_failed', 'scenario_version' => 1,
            'scenario_sha256' => $digestValid ? $result['scenario_sha256'] : null, 'database' => 'sqlite_in_memory_child_process',
            'checks' => $checks, 'tests' => $result['tests'], 'assertions' => $result['assertions'],
            'rollback_verified' => $passed, 'import_authorized' => false, 'data002_closed' => false,
            'business_signature_verified' => false, 'real_data_processed' => false,
            'not_verified' => ['real_source_reconciliation', 'business_signed_sample', 'hosted_workers_and_storage',
                'postgresql_rehearsal', 'independent_worker_transactions', 'backup_restoration', 'additional_source_formats']];
    }
}
