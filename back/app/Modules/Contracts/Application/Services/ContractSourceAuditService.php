<?php

namespace App\Modules\Contracts\Application\Services;

use App\Modules\Contracts\Application\Contracts\ContractSourceAudit;
use App\Modules\Contracts\Application\Validation\ContractInput;
use App\Support\Files\JsonDatasetAuditor;
use Illuminate\Validation\ValidationException;

final readonly class ContractSourceAuditService implements ContractSourceAudit
{
    public function __construct(private JsonDatasetAuditor $auditor) {}

    public function analyze(string $contents, string $organization, int $maximumRows = 100): array
    {
        return $this->auditor->analyze($contents, $organization, ContractInput::saveRules(true), function (array $values): array {
            $values = $this->auditor->canonical($values);
            $values += ['starts_on' => null, 'ends_on' => null, 'obligations' => [], 'signature_separation_required' => false];
            if ($values['ends_on'] !== null && ($values['starts_on'] === null || $values['ends_on'] < $values['starts_on'])) {
                throw ValidationException::withMessages(['ends_on' => __('managed_contracts.dates_order')]);
            }
            foreach ($values['parties'] as $party) {
                if ($party['kind'] === 'external' && ($party['person_id'] ?? null) !== null) {
                    throw ValidationException::withMessages(['parties' => __('managed_contracts.party')]);
                }
            }

            return ['identity' => [$values['reference']], 'payload' => $values];
        }, $maximumRows);
    }
}
