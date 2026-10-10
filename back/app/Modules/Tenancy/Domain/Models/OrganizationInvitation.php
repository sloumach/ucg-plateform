<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Domain\InvitationStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property list<string> $roles
 * @property InvitationStatus $status
 * @property int $invited_by
 * @property int|null $responded_by
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $responded_at
 * @property-read Organization $organization
 */
class OrganizationInvitation extends Model
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id', 'organization_id', 'email', 'invited_by'];

    protected static function newFactory(): OrganizationInvitationFactory
    {
        return OrganizationInvitationFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function (self $invitation): void {
            if ($invitation->isDirty(['id', 'organization_id', 'email', 'invited_by'])) {
                throw new DomainConflictException(__('tenancy.errors.access_identity'), 'ACCESS_IDENTITY_IMMUTABLE');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => InvitationStatus::class, 'roles' => 'array', 'invited_by' => 'integer', 'responded_by' => 'integer',
            'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'responded_at' => 'immutable_datetime'];
    }

    public function effectiveStatus(CarbonInterface $at): InvitationStatus
    {
        return $this->status === InvitationStatus::Pending && $this->expires_at->lessThanOrEqualTo($at)
            ? InvitationStatus::Expired : $this->status;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
