<?php

namespace App\Modules\People\Domain\Models;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Domain\PersonStatus;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property int|null $user_id
 * @property string $pseudonym
 * @property string $language
 * @property string $timezone
 * @property PersonStatus $status
 * @property string|null $biography_public
 * @property string|null $biography_internal
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string $version
 */
final class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory, HasUuids;

    protected $table = 'people';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'user_id', 'first_name', 'last_name', 'contact_email', 'contact_phone', 'biography_internal'];

    protected static function newFactory(): PersonFactory
    {
        return PersonFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'status' => PersonStatus::class, 'first_name' => 'encrypted',
            'last_name' => 'encrypted', 'contact_email' => 'encrypted', 'contact_phone' => 'encrypted',
            'biography_internal' => 'encrypted'];
    }

    /** @param Builder<Person> $query
     * @return Builder<Person>
     */
    #[Scope]
    protected function forOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->where('organization_id', strtolower($organizationId));
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new DomainConflictException(__('people.history_retained'), 'PERSON_HISTORY_RETAINED'));
        self::updating(function (self $person): void {
            if ($person->isDirty(['id', 'organization_id'])) {
                throw new DomainConflictException(__('people.immutable'), 'PERSON_IDENTITY_IMMUTABLE');
            }
        });
    }
}
