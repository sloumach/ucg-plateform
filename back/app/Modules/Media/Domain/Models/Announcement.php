<?php

namespace App\Modules\Media\Domain\Models;

use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property string $id
 * @property string $organization_id
 * @property string $title
 * @property string $body
 * @property string $target_type
 * @property string|null $team_id
 * @property string|null $game_id
 * @property string|null $role_id
 * @property string $status
 * @property bool $comments_enabled
 * @property int $created_by
 * @property string $version
 * @property Carbon|null $published_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $withdrawn_at
 */
final class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory, HasUuids;

    protected $attributes = ['comments_enabled' => true];

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'created_by', 'published_by'];

    protected static function newFactory(): AnnouncementFactory
    {
        return AnnouncementFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['title' => 'encrypted', 'body' => 'encrypted', 'created_by' => 'integer', 'comments_enabled' => 'boolean',
            'published_at' => 'datetime', 'expires_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }

    public function targetId(): ?string
    {
        return match ($this->target_type) {
            'team' => $this->team_id, 'game' => $this->game_id, 'role' => $this->role_id, default => null
        };
    }
}
