<?php

namespace App\Modules\Academy\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $kind
 * @property string $resource_id
 * @property int $actor_user_id
 * @property CarbonImmutable $recorded_at */
final class AcademyCoachingRevision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'snapshot'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'actor_user_id' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }
}
