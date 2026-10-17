<?php

namespace App\Modules\Competition\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MatchRecordFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $id
 * @property string $organization_id
 * @property string $game_id
 * @property string $game_key
 * @property string $game_name
 * @property string $title
 * @property string $kind
 * @property string $format
 * @property string $status
 * @property string $visibility
 * @property string $version
 * @property string $responsible_person_id
 * @property string $responsible_label
 * @property int $created_by
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $origin_timezone
 * @property-read Collection<int,MatchParticipant> $participants
 */
final class MatchRecord extends Model
{
    /** @use HasFactory<MatchRecordFactory> */
    use HasFactory, HasUuids;

    protected $table = 'matches';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'created_by', 'sharing_authorized_by'];

    protected static function newFactory(): MatchRecordFactory
    {
        return MatchRecordFactory::new();
    }

    /** @return HasMany<MatchParticipant,$this> */
    public function participants(): HasMany
    {
        return $this->hasMany(MatchParticipant::class, 'match_id')->orderBy('slot');
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['title' => 'encrypted', 'responsible_label' => 'encrypted', 'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime', 'sharing_authorized_at' => 'immutable_datetime', 'created_by' => 'integer'];
    }
}
