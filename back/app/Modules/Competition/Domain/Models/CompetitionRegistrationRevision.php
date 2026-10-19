<?php

namespace App\Modules\Competition\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $competition_id
 * @property CarbonImmutable $created_at
 * @property int $number
 * @property array<string,mixed> $payload
 * @property string $reason
 * @property int $actor_user_id
 * @property ?CarbonImmutable $next_due_at
 * @property ?CarbonImmutable $checked_at */
final class CompetitionRegistrationRevision extends Model
{
    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['payload', 'reason', 'organization_id'];

    /**
     * @return array<string,string> */
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'reason' => 'encrypted', 'number' => 'integer', 'actor_user_id' => 'integer', 'next_due_at' => 'immutable_datetime', 'checked_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
