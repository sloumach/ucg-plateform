<?php

namespace App\Modules\Teams\Domain\Models;

use App\Exceptions\DomainConflictException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $opponent_id
 * @property string $opponent_version
 * @property string $context_label
 * @property string $kind
 * @property string $name
 * @property string|null $short_name
 * @property string|null $organization_name
 * @property string|null $game_key
 * @property string|null $game_name
 * @property string $source_label
 * @property string|null $source_url
 * @property CarbonImmutable $observed_at
 * @property CarbonImmutable $captured_at
 * @property int $created_by
 */
final class ExternalOpponentSnapshot extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime', 'captured_at' => 'immutable_datetime', 'created_by' => 'integer'];
    }

    /** @return array<string, mixed> */
    public function projection(): array
    {
        return ['id' => $this->id, 'opponent_id' => $this->opponent_id, 'opponent_version' => $this->opponent_version,
            'context_label' => $this->context_label, 'kind' => $this->kind, 'name' => $this->name, 'short_name' => $this->short_name,
            'organization_name' => $this->organization_name, 'game_key' => $this->game_key, 'game_name' => $this->game_name,
            'source_label' => $this->source_label, 'source_url' => $this->source_url, 'observed_at' => $this->observed_at->toIso8601String(),
            'captured_at' => $this->captured_at->toIso8601String(), 'created_by' => $this->created_by, 'publication_status' => 'private'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new DomainConflictException(__('external_opponents.frozen'), 'EXTERNAL_OPPONENT_SNAPSHOT_IMMUTABLE'));
        self::deleting(fn () => throw new DomainConflictException(__('external_opponents.frozen'), 'EXTERNAL_OPPONENT_SNAPSHOT_IMMUTABLE'));
    }
}
