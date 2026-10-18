<?php

namespace App\Modules\Competition\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $competition_id
 * @property string $document_id
 * @property int $number
 * @property string $kind
 * @property string $title
 * @property string $body
 * @property ?string $source_url
 * @property string $reason
 * @property int $actor_user_id
 * @property CarbonImmutable $created_at */
final class ExternalCompetitionDocument extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'title', 'body', 'source_url', 'reason'];

    /**
     * @return array<string,string> */
    protected function casts(): array
    {
        return ['number' => 'integer', 'title' => 'encrypted', 'body' => 'encrypted', 'source_url' => 'encrypted', 'reason' => 'encrypted', 'actor_user_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
