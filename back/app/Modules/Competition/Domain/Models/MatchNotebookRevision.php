<?php

namespace App\Modules\Competition\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $match_id
 * @property string $entry_id
 * @property int $number
 * @property string $kind
 * @property string $audience
 * @property string $title
 * @property string $body
 * @property ?string $vod_url
 * @property ?int $timestamp_seconds
 * @property ?string $state
 * @property ?CarbonImmutable $due_at
 * @property int $actor_user_id
 * @property CarbonImmutable $created_at
 */
final class MatchNotebookRevision extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'title', 'body', 'vod_url'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['title' => 'encrypted', 'body' => 'encrypted', 'vod_url' => 'encrypted', 'number' => 'integer', 'timestamp_seconds' => 'integer',
            'actor_user_id' => 'integer', 'created_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime'];
    }
}
