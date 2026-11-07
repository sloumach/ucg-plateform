<?php

namespace App\Modules\Media\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class MediaContext extends Model
{
    public const array COLUMNS = ['event' => 'activity_id', 'match' => 'match_id'];

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $keyType = 'string';

    public $incrementing = false;

    public function type(): string
    {
        return $this->getAttribute('activity_id') !== null ? 'event' : 'match';
    }

    public function sourceId(): string
    {
        return $this->getAttribute('activity_id') ?? $this->getAttribute('match_id');
    }
}
