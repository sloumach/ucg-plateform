<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SponsorshipAgreement> */
class SponsorshipAgreementFactory extends Factory
{
    protected $model = SponsorshipAgreement::class;

    public function definition(): array
    {
        return ['sponsor_id' => Sponsor::factory(), 'organization_id' => fn (array $attributes): string => Sponsor::query()->whereKey($attributes['sponsor_id'])->firstOrFail()->organization_id,
            'opportunity_id' => null, 'reference' => fake()->unique()->slug(3), 'title' => 'Accord partenaire',
            'starts_on' => today(), 'ends_on' => today()->addMonth(), 'responsible_user_id' => User::factory()->withMfa(),
            'contract_id' => null, 'contract_version' => null, 'status' => 'draft', 'version' => (string) Str::uuid(),
            'prepared_by' => User::factory()->withMfa(), 'approved_by' => null, 'approved_at' => null, 'approved_financial_version' => null];
    }
}
