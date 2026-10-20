<?php

namespace App\Modules\People\Domain\Contracts;

use App\Modules\People\Domain\Models\Person;
use App\Support\Data\SearchPage;
use Illuminate\Pagination\LengthAwarePaginator;

interface PersonRepository
{
    public function search(string $org, string $term, ?string $after, int $size): SearchPage;

    /** @return array<string,int> */
    public function dashboardCounts(string $org, string $card): array;

    /** @param list<string> $ids
     * @return array<string,array{label:string,version:string}>
     */
    public function sportingSnapshots(string $org, array $ids): array;

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, array{id: string, label: string}> */
    public function partnershipPage(string $org, int $page, int $perPage): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    /** @param list<string> $ids
     * @return array<string, string>
     */
    public function partnershipLabels(string $org, array $ids): array;

    public function linkedAccount(string $organizationId, string $personId): ?int;

    /** @param list<string> $ids
     * @return list<Person>
     */
    public function contractPeople(string $organizationId, array $ids): array;

    /** @return LengthAwarePaginator<int, Person> */
    public function contractPeoplePage(string $organizationId, int $page, int $perPage): LengthAwarePaginator;

    /** @param list<string> $ids
     * @return list<Person>
     */
    public function eligibilitySubjects(string $org, array $ids, string $gameKey, ?string $platform, bool $birth): array;

    /** @param list<string> $ids
     * @return array<string,string>
     */
    public function pseudonyms(string $org, array $ids): array;

    public function personForAccount(string $org, int $userId): ?string;

    public function hasDeparted(string $organizationId, string $personId): bool;

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Person>
     */
    public function paginate(string $organizationId, array $filters, bool $sports, bool $administrative): LengthAwarePaginator;

    public function find(string $organizationId, string $personId, bool $lock = false): Person;

    /** @param array<string, mixed> $attributes */
    public function create(string $organizationId, array $attributes): Person;

    /** @param array<string, mixed> $attributes */
    public function update(Person $person, array $attributes): Person;
}
