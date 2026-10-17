<?php

namespace App\Modules\Scheduling\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CalendarInvitationDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $invitation_id
 * @property string $kind
 * @property int $offset_minutes
 * @property string $run_id
 * @property CarbonImmutable $created_at
 */
final class CalendarInvitationDelivery extends Model
{
    /** @use HasFactory<CalendarInvitationDeliveryFactory> */
    use HasFactory, HasUuids;

    protected static function newFactory(): CalendarInvitationDeliveryFactory
    {
        return CalendarInvitationDeliveryFactory::new();
    }

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'run_id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['offset_minutes' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
