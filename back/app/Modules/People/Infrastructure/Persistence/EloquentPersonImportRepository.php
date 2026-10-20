<?php

namespace App\Modules\People\Infrastructure\Persistence;

use App\Modules\People\Domain\Contracts\PersonImportRepository;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\People\Domain\Models\PersonImportRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class EloquentPersonImportRepository implements PersonImportRepository
{
    /** @return array<string,int> */
    public function dashboardCounts(string $org): array
    {
        return PersonImport::query()->where('organization_id', $org)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->orderBy('status')->toBase()->get()->mapWithKeys(fn ($r) => [$r->status => (int) $r->aggregate])->all();
    }

    public function find(string $organizationId, string $id, bool $lock = false): PersonImport
    {
        if (! Str::isUuid($id)) {
            throw new ModelNotFoundException;
        }

        return PersonImport::query()->where('organization_id', $organizationId)->whereKey(strtolower($id))
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    public function byFingerprint(string $organizationId, string $fingerprint): ?PersonImport
    {
        return PersonImport::query()->where('organization_id', $organizationId)->where('fingerprint', $fingerprint)->first();
    }

    public function create(string $organizationId, array $values): PersonImport
    {
        $run = new PersonImport;
        $run->forceFill([...$values, 'organization_id' => $organizationId])->save();

        return $run;
    }

    public function save(PersonImport $run, array $values): PersonImport
    {
        $run->forceFill($values)->save();

        return $run;
    }

    public function paginate(string $organizationId, int $page): LengthAwarePaginator
    {
        return PersonImport::query()->where('organization_id', $organizationId)->orderByDesc('id')->paginate(10, ['*'], 'page', $page);
    }

    public function rows(PersonImport $run, int $page): LengthAwarePaginator
    {
        return $this->rowQuery($run)->orderBy('line')->paginate(10, ['*'], 'page', $page);
    }

    public function ready(PersonImport $run, int $limit): array
    {
        return array_values($this->rowQuery($run)->where('status', 'ready')->orderBy('line')->limit($limit)->lockForUpdate()->get()->all());
    }

    public function replaceRows(PersonImport $run, array $rows): void
    {
        $this->rowQuery($run)->delete();
        $encoded = [];
        foreach ($rows as $values) {
            $row = new PersonImportRow;
            $row->forceFill([...$values, 'id' => (string) Str::uuid(), 'organization_id' => $run->organization_id,
                'import_id' => $run->id, 'created_at' => now(), 'updated_at' => now()]);
            $encoded[] = $row->getAttributes();
        }
        foreach (array_chunk($encoded, 50) as $chunk) {
            PersonImportRow::query()->insert($chunk);
        }
    }

    public function saveRow(PersonImportRow $row, array $values): void
    {
        $row->forceFill($values)->save();
    }

    public function counts(string $organizationId, array $ids): array
    {
        $result = [];
        foreach (PersonImportRow::query()->where('organization_id', $organizationId)->whereIn('import_id', $ids)
            ->selectRaw('import_id, status, COUNT(*) AS aggregate')->groupBy('import_id', 'status')->get() as $row) {
            $result[$row->import_id][$row->status] = (int) $row->getAttribute('aggregate');
        }

        return $result;
    }

    public function existingPseudonyms(string $organizationId, array $pseudonyms): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($pseudonyms)), 100) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), 'LOWER(?)'));
            foreach (Person::query()->forOrganization($organizationId)
                ->whereRaw('LOWER(TRIM(pseudonym)) IN ('.$placeholders.')', $chunk)
                ->selectRaw('LOWER(TRIM(pseudonym)) AS pseudonym')->distinct()->limit(count($chunk))->get() as $person) {
                $found[] = mb_strtolower(trim($person->pseudonym));
            }
        }

        return array_values(array_unique($found));
    }

    public function reportRows(PersonImport $run): iterable
    {
        return $this->rowQuery($run)->orderBy('line')->lazy(100);
    }

    /** @return Builder<PersonImportRow> */
    private function rowQuery(PersonImport $run): Builder
    {
        return PersonImportRow::query()->where('organization_id', $run->organization_id)->where('import_id', $run->id);
    }
}
