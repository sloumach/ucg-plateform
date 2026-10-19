<?php

namespace App\Modules\Competition\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $id
 * @property string $organization_id
 * @property string $competition_id
 * @property string $stream
 * @property int $number
 * @property array<string,mixed> $payload
 * @property string $reason
 * @property int $actor_user_id
 * @property CarbonImmutable $created_at
 * @property-read Collection<int,CompetitionOutcomeMatch> $links */
final class CompetitionOutcomeRevision extends Model
{
    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['payload', 'reason', 'organization_id', 'links'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'reason' => 'encrypted', 'number' => 'integer', 'actor_user_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<CompetitionOutcomeMatch,$this> */
    public function links(): HasMany
    {
        return $this->hasMany(CompetitionOutcomeMatch::class, 'revision_id')->orderBy('reference_id');
    }
}
