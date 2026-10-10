<?php

namespace App\Modules\Tenancy\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $organization_id
 * @property int $week_starts_on
 * @property string $date_format
 */
class OrganizationSetting extends Model
{
    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['week_starts_on', 'date_format'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['week_starts_on' => 'integer'];
    }
}
