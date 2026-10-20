<?php

namespace App\Modules\People\Domain\Contracts;

use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\People\Domain\Models\PersonImportRow;
use Illuminate\Pagination\LengthAwarePaginator;

interface PersonImportRepository
{
    /** @return array<string,int> */
    public function dashboardCounts(string $org): array;

    public function find(string $organizationId, string $id, bool $lock = false): PersonImport;

    public function byFingerprint(string $organizationId, string $fingerprint): ?PersonImport;

    /** @param array<string,mixed> $values */
    public function create(string $organizationId, array $values): PersonImport;

    /** @param array<string,mixed> $values */
    public function save(PersonImport $run, array $values): PersonImport;

    /** @return LengthAwarePaginator<int,PersonImport> */
    public function paginate(string $organizationId, int $page): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int,PersonImportRow> */
    public function rows(PersonImport $run, int $page): LengthAwarePaginator;

    /** @return list<PersonImportRow> */
    public function ready(PersonImport $run, int $limit): array;

    /** @param list<array<string,mixed>> $rows */
    public function replaceRows(PersonImport $run, array $rows): void;

    /** @param array<string,mixed> $values */
    public function saveRow(PersonImportRow $row, array $values): void;

    /** @param list<string> $ids
     * @return array<string,array<string,int>> */
    public function counts(string $organizationId, array $ids): array;

    /** @param list<string> $pseudonyms
     * @return list<string> */
    public function existingPseudonyms(string $organizationId, array $pseudonyms): array;

    /** @return iterable<PersonImportRow> */
    public function reportRows(PersonImport $run): iterable;
}
