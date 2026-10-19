<?php

namespace App\Modules\Competition\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $competition_id
 * @property CarbonImmutable $created_at
 * @property string $revision_id
 * @property string $item_id
 * @property int $recipient_id
 * @property string $stage
 * @property string $occurrence
 * @property array<string,mixed> $payload */
final class CompetitionRegistrationReminder extends Model
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
        return ['payload' => 'encrypted:array', 'recipient_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
