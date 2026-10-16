<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SponsorshipCampaign> */
class SponsorshipCampaignFactory extends Factory
{
    protected $model = SponsorshipCampaign::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['agreement_id' => SponsorshipAgreement::factory(),
            'organization_id' => fn (array $v) => SponsorshipAgreement::query()->whereKey($v['agreement_id'])->firstOrFail()->organization_id,
            'title' => 'Campagne '.fake()->word(), 'starts_on' => today(), 'ends_on' => today()->addMonth(),
            'responsible_user_id' => User::factory()->withMfa(), 'created_by' => User::factory()->withMfa(),
            'status' => 'active', 'version' => (string) Str::uuid()];
    }
}
