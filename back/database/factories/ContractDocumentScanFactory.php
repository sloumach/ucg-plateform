<?php

namespace Database\Factories;

use App\Modules\Contracts\Domain\DocumentSecurity;
use App\Modules\Contracts\Domain\Models\ContractDocumentScan;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContractDocumentScan> */
class ContractDocumentScanFactory extends Factory
{
    protected $model = ContractDocumentScan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['policy' => DocumentSecurity::POLICY, 'status' => 'queued', 'reason' => null, 'engine' => null,
            'requested_at' => now(), 'started_at' => null, 'finished_at' => null, 'invalidated_at' => null];
    }

    /** A queued fixture must reference a real immutable version; clean verdicts always use the processor. */
    public function forVersion(ContractDocumentVersion $version, int $actor): static
    {
        return $this->state(fn (): array => ['organization_id' => $version->organization_id, 'contract_id' => $version->contract_id,
            'document_id' => $version->document_id, 'document_version_id' => $version->id, 'sha256' => $version->sha256,
            'requested_by' => $actor, 'attempt' => (ContractDocumentScan::query()->where('organization_id', $version->organization_id)
                ->where('document_version_id', $version->id)->max('attempt') ?? 0) + 1]);
    }
}
