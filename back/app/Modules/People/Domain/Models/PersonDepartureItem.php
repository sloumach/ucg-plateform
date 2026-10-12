<?php

namespace App\Modules\People\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PersonDepartureItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $person_id
 * @property string $kind
 * @property string $title
 * @property string|null $notes
 * @property string $status
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $completed_at
 * @property string $version
 */
final class PersonDepartureItem extends Model
{
    /** @use HasFactory<PersonDepartureItemFactory> */
    use HasFactory, HasUuids, PersonLifecycleIdentity;

    protected $table = 'person_departure_items';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'title', 'notes'];

    protected static function newFactory(): PersonDepartureItemFactory
    {
        return PersonDepartureItemFactory::new();
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['title' => 'encrypted', 'notes' => 'encrypted', 'due_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
