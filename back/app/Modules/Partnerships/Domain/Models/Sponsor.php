<?php

namespace App\Modules\Partnerships\Domain\Models;

use Database\Factories\SponsorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $industry
 * @property string $status
 * @property string $version
 */
final class Sponsor extends Model
{
    /** @use HasFactory<SponsorFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected static function newFactory(): SponsorFactory
    {
        return SponsorFactory::new();
    }
}
