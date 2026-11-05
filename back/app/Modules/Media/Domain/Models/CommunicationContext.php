<?php

namespace App\Modules\Media\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $announcement_id
 * @property string|null $activity_id
 * @property string|null $match_id
 * @property string|null $session_id
 * @property bool $discussion */
final class CommunicationContext extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $keyType = 'string';

    public $incrementing = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['discussion' => 'boolean'];
    }

    public function type(): string
    {
        return $this->activity_id !== null ? 'event' : ($this->match_id !== null ? 'match' : 'session');
    }

    public function targetId(): string
    {
        return $this->activity_id ?? $this->match_id ?? (string) $this->session_id;
    }
}
