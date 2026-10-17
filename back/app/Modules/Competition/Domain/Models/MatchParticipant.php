<?php

namespace App\Modules\Competition\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $match_id
 * @property string $organization_id
 * @property string $kind
 * @property string $label
 * @property string $reference_version
 * @property string|null $team_id
 * @property string|null $person_id
 * @property string|null $opponent_snapshot_id
 * @property int $slot
 */
final class MatchParticipant extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['label' => 'encrypted', 'slot' => 'integer'];
    }

    public function sourceId(): string
    {
        return $this->team_id ?? $this->person_id ?? $this->opponent_snapshot_id ?? throw new \LogicException('Invalid participant identity.');
    }
}
