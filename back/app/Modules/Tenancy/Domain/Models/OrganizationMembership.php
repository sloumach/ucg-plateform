<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Domain\MembershipStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\OrganizationMembershipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property int $user_id
 * @property MembershipStatus $status
 * @property list<string> $roles
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property-read Organization $organization
 */
class OrganizationMembership extends Model
{
    /** @use HasFactory<OrganizationMembershipFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id', 'organization_id', 'user_id'];

    protected static function newFactory(): OrganizationMembershipFactory
    {
        return OrganizationMembershipFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function (self $membership): void {
            if ($membership->isDirty(['id', 'organization_id', 'user_id'])) {
                throw new DomainConflictException(__('tenancy.errors.access_identity'), 'ACCESS_IDENTITY_IMMUTABLE');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => MembershipStatus::class, 'roles' => 'array', 'user_id' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    /** @param Builder<static> $query */
    public function scopeEffective(Builder $query, CarbonInterface $at): void
    {
        $query->where('status', MembershipStatus::Active)->where('starts_at', '<=', $at)
            ->where(fn (Builder $period) => $period->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    public function isEffective(CarbonInterface $at): bool
    {
        return $this->status === MembershipStatus::Active && $this->starts_at->lessThanOrEqualTo($at)
            && ($this->ends_at === null || $this->ends_at->greaterThan($at));
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
