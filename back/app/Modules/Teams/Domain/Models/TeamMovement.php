<?php

namespace App\Modules\Teams\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $team_id
 * @property string $membership_id
 * @property string|null $destination_team_id
 * @property string|null $destination_membership_id
 * @property string $action
 * @property string $reason_key
 * @property int $actor_user_id
 * @property CarbonImmutable $effective_at
 * @property Team $team
 * @property Team|null $destinationTeam
 */
final class TeamMovement extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /** @return BelongsTo<Team,$this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /** @return BelongsTo<Team,$this> */
    public function destinationTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'destination_team_id');
    }

    protected function casts(): array
    {
        return ['effective_at' => 'immutable_datetime', 'actor_user_id' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Team movements are append-only.'));
        self::deleting(fn () => throw new \LogicException('Team movements are append-only.'));
    }
}
