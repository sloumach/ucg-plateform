<?php

namespace Database\Factories;

use App\Modules\Contracts\Domain\Models\ContractDocument;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContractDocument> */
class ContractDocumentFactory extends Factory
{
    protected $model = ContractDocument::class;

    public function definition(): array
    {
        return ['contract_id' => ContractRecord::factory(),
            'organization_id' => fn (array $attributes): string => ContractRecord::query()->whereKey($attributes['contract_id'])->firstOrFail()->organization_id,
            'created_by' => fn (array $attributes): int => ContractRecord::query()->whereKey($attributes['contract_id'])->firstOrFail()->created_by,
            'reference' => 'original', 'kind' => 'original', 'title' => 'Document original',
            'original_document_id' => null, 'effective_on' => null, 'created_at' => now()];
    }
}
