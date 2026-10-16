<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaign;
use App\Modules\Partnerships\Domain\Models\SponsorshipDeliverable;
use App\Modules\Partnerships\Domain\Models\SponsorshipExposure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SponsorshipDeliverable> */
class SponsorshipDeliverableFactory extends Factory
{
    protected $model = SponsorshipDeliverable::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['campaign_id' => SponsorshipCampaign::factory(),
            'organization_id' => fn (array $v) => SponsorshipCampaign::query()->whereKey($v['campaign_id'])->firstOrFail()->organization_id,
            'agreement_id' => fn (array $v) => SponsorshipCampaign::query()->whereKey($v['campaign_id'])->firstOrFail()->agreement_id,
            'exposure_id' => fn (array $v) => SponsorshipExposure::query()->forceCreate(['organization_id' => $v['organization_id'], 'agreement_id' => $v['agreement_id'],
                'target_type' => 'organization', 'team_id' => null, 'person_id' => null, 'target_label' => 'Organisation', 'channel' => 'website',
                'authorization_reference' => 'Autorisation de test', 'status' => 'active', 'version' => (string) Str::uuid()])->id,
            'kind' => 'publication', 'title' => 'Publication promise', 'responsible_user_id' => User::factory()->withMfa(), 'due_at' => now(),
            'status' => 'promised', 'proof_reference' => null, 'delivered_at' => null, 'delivered_by' => null,
            'validated_at' => null, 'validated_by' => null, 'validated_agreement_version' => null, 'validated_exposure_version' => null,
            'prepared_by' => User::factory()->withMfa(), 'version' => (string) Str::uuid()];
    }
}
