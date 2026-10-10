<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property int $owner_user_id
 * @property OrganizationStatus $status
 * @property string $timezone
 * @property string $language
 * @property string $country
 * @property-read OrganizationSetting|null $settings
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'timezone', 'language', 'country'];

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function (self $organization): void {
            if ($organization->isDirty(['id', 'slug', 'owner_user_id'])) {
                throw new DomainConflictException(__('tenancy.errors.identity_immutable'), 'ORGANIZATION_IDENTITY_IMMUTABLE');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => OrganizationStatus::class, 'owner_user_id' => 'integer'];
    }

    /** @return HasOne<OrganizationSetting, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(OrganizationSetting::class);
    }

    public function acceptsBusinessOperations(): bool
    {
        return $this->status === OrganizationStatus::Active;
    }
}
