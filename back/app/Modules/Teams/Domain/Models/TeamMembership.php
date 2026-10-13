<?php

namespace App\Modules\Teams\Domain\Models;

use App\Exceptions\DomainConflictException;
use Carbon\CarbonImmutable;
use Database\Factories\TeamMembershipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $team_id
 * @property string $person_id
 * @property string $role_key
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property string $status
 * @property string $version
 */
final class TeamMembership extends Model
{
    /** @use HasFactory<TeamMembershipFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    protected static function newFactory(): TeamMembershipFactory
    {
        return TeamMembershipFactory::new();
    }

    public function effectiveAt(CarbonImmutable $at): bool
    {
        return $this->status !== 'cancelled' && $this->starts_at->lessThanOrEqualTo($at) && ($this->ends_at === null || $this->ends_at->greaterThan($at));
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new DomainConflictException(__('team_memberships.history'), 'TEAM_MEMBERSHIP_HISTORY_RETAINED'));
        self::updating(function (self $membership): void {
            if ($membership->isDirty(['id', 'organization_id', 'team_id', 'person_id', 'role_key', 'starts_at']) || $membership->getOriginal('status') !== 'active') {
                throw new DomainConflictException(__('team_memberships.terminal'), 'TEAM_MEMBERSHIP_IMMUTABLE');
            }
        });
    }
}
