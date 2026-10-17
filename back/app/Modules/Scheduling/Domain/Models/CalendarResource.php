<?php

namespace App\Modules\Scheduling\Domain\Models;

use Database\Factories\CalendarResourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $key
 * @property string $label
 * @property string $kind
 * @property string $status
 * @property string $version
 */
final class CalendarResource extends Model
{
    /** @use HasFactory<CalendarResourceFactory> */
    use HasFactory,HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'created_by'];

    protected static function newFactory(): CalendarResourceFactory
    {
        return CalendarResourceFactory::new();
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['label' => 'encrypted'];
    }
}
