<?php

namespace App\Modules\Competition\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $match_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string $match_version
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 */
final class MatchStatusEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'actor_user_id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'created_at' => 'immutable_datetime'];
    }
}
