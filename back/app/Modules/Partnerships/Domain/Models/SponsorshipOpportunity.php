<?php

namespace App\Modules\Partnerships\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $sponsor_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property string $version
 */
final class SponsorshipOpportunity extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];
}
