<?php

namespace App\Modules\Scheduling\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CalendarFeedFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property int $user_id
 * @property string|null $token_hash
 * @property int $before_days
 * @property int $after_days
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property string $version
 */
final class CalendarFeed extends Model
{
    /** @use HasFactory<CalendarFeedFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'user_id', 'token_hash'];

    protected static function newFactory(): CalendarFeedFactory
    {
        return CalendarFeedFactory::new();
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'before_days' => 'integer', 'after_days' => 'integer',
            'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
