<?php

namespace App\Modules\Partnerships\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $agreement_id
 * @property string $target_type
 * @property string|null $team_id
 * @property string|null $person_id
 * @property string $target_label
 * @property string $channel
 * @property string|null $description
 * @property string $authorization_reference
 * @property string|null $exclusivity_key
 * @property string $status
 * @property string $version
 */
final class SponsorshipExposure extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['authorization_reference' => 'encrypted', 'target_label' => 'encrypted'];
    }
}
