<?php

namespace App\Modules\Teams\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $lineup_id
 * @property string $binding_id
 * @property string $ruleset_id
 * @property string $ruleset_version
 * @property string $lineup_version
 * @property string $stage
 * @property string $status
 * @property CarbonImmutable $evaluated_at
 * @property int $actor_user_id
 * @property list<array{code:string,status:string,person_id:string|null}> $checks
 */
final class TeamLineupEligibilityReport extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['evaluated_at' => 'immutable_datetime', 'checks' => 'array'];
    }
}
