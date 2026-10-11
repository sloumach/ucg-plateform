<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Exceptions\DomainConflictException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\OrganizationSupportAccessFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property list<string> $actions
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $expires_at
 */
final class OrganizationSupportAccess extends Model
{
    public const int MAX_DURATION_MINUTES = 30;

    /** @use HasFactory<OrganizationSupportAccessFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected static function newFactory(): OrganizationSupportAccessFactory
    {
        return OrganizationSupportAccessFactory::new();
    }

    protected static function booted(): void
    {
        self::updating(function (self $access): void {
            if ($access->isDirty(['id', 'organization_id', 'actor_user_id', 'platform_role_version', 'reason', 'actions', 'starts_at', 'expires_at'])
                || ($access->getRawOriginal('status') === 'revoked' && $access->status !== 'revoked')) {
                throw new DomainConflictException(__('platform.identity_immutable'), 'SUPPORT_ACCESS_IMMUTABLE');
            }
        });
    }

    public function effectiveStatus(CarbonInterface $at): string
    {
        return $this->status === 'revoked' ? 'revoked' : ($at->greaterThanOrEqualTo($this->expires_at) ? 'expired' : 'active');
    }

    public function allows(string $action, CarbonInterface $at): bool
    {
        return $this->effectiveStatus($at) === 'active' && $at->greaterThanOrEqualTo($this->starts_at) && in_array($action, $this->actions, true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['actions' => 'array', 'starts_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'actor_user_id' => 'integer'];
    }
}
