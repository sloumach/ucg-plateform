<?php

namespace App\Modules\Teams\Domain\Models;

use Database\Factories\TeamLineupEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $lineup_id
 * @property string $membership_id
 * @property string $membership_version
 * @property string $person_id
 * @property string $pseudonym
 * @property string $membership_role_key
 * @property string $slot_key
 * @property int $position
 */
final class TeamLineupEntry extends Model
{
    /** @use HasFactory<TeamLineupEntryFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    protected static function newFactory(): TeamLineupEntryFactory
    {
        return TeamLineupEntryFactory::new();
    }
}
