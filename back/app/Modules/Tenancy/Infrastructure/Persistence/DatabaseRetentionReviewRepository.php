<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Contracts\RetentionReviewRepository;
use App\Modules\Tenancy\Domain\RetentionCatalog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\JoinClause;
use InvalidArgumentException;
use stdClass;

final readonly class DatabaseRetentionReviewRepository implements RetentionReviewRepository
{
    private const array PERSON_REFERENCES = ['person_game_profiles' => 'person_id', 'person_periods' => 'person_id',
        'person_departure_items' => 'person_id', 'person_departures' => 'person_id', 'team_memberships' => 'person_id',
        'team_lineup_entries' => 'person_id', 'match_participants' => 'person_id', 'matches' => 'responsible_person_id',
        'contracts' => 'responsible_person_id', 'contract_parties' => 'person_id', 'academy_enrollments' => 'person_id',
        'statistical_observations' => 'person_id', 'calendar_change_recipients' => 'person_id', 'calendar_invitations' => 'person_id',
        'availabilities' => 'person_id', 'availability_revisions' => 'person_id', 'calendar_participants' => 'person_id',
        'sponsorship_exposures' => 'person_id', 'contract_reminder_deliveries' => 'person_id', 'person_import_rows' => 'person_id'];

    public function __construct(private ConnectionInterface $database, private PortabilityRepository $portability) {}

    public function assertCatalog(): void
    {
        $tables = RetentionCatalog::tables();
        if (count($tables) !== count(array_unique($tables)) || array_diff($tables, array_keys(DatabasePortabilityRepository::TABLES)) !== []
            || array_diff(array_keys(DatabasePortabilityRepository::TABLES), $tables) !== []) {
            throw new DomainConflictException(__('retention.catalog_incomplete'), 'RETENTION_CATALOG_INCOMPLETE');
        }
        $this->portability->assertCatalog();
    }

    public function counts(string $organizationId, array $tables): array
    {
        if (count($tables) > count(RetentionCatalog::tables()) || array_diff($tables, RetentionCatalog::tables()) !== []) {
            throw new InvalidArgumentException('Only the closed retention inventory is supported.');
        }
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = $this->database->table($table)->where($table === 'organizations' ? 'id' : 'organization_id', $organizationId)->count();
        }

        return $counts;
    }

    public function people(string $organizationId, int $perPage, int $page): LengthAwarePaginator
    {
        if ($perPage < 1 || $perPage > 100 || $page < 1 || $page > 10000) {
            throw new InvalidArgumentException('Retention records require a bounded page.');
        }
        $result = $this->database->table('people as p')->where('p.organization_id', $organizationId)
            ->leftJoin('person_departures as d', function (JoinClause $join): void {
                $join->on('d.person_id', '=', 'p.id')->on('d.organization_id', '=', 'p.organization_id');
            })->select(['p.id', 'p.status', 'd.departed_at'])
            ->selectRaw('CASE WHEN p.user_id IS NULL THEN 0 ELSE 1 END AS account_linked')
            ->orderBy('p.id')->paginate($perPage, ['*'], 'page', $page);
        $items = [];
        foreach ($result->items() as $row) {
            $items[] = $this->personRow($row);
        }

        return new \Illuminate\Pagination\LengthAwarePaginator($items, $result->total(), $perPage, $page);
    }

    /** @return array<string, mixed> */
    private function personRow(stdClass $row): array
    {
        return ['id' => (string) $row->id, 'status' => (string) $row->status,
            'departed_at' => $row->departed_at, 'account_linked' => (bool) $row->account_linked];
    }

    public function dependencies(string $organizationId, array $personIds): array
    {
        if (count($personIds) > 100) {
            throw new InvalidArgumentException('Retention dependencies require a bounded page.');
        }
        if ($personIds === []) {
            return [];
        }
        $result = array_fill_keys($personIds, []);
        foreach (self::PERSON_REFERENCES as $table => $column) {
            $counts = $this->database->table($table)->where('organization_id', $organizationId)->whereIn($column, $personIds)
                ->select($column)->selectRaw('COUNT(*) AS aggregate')->groupBy($column)->pluck('aggregate', $column);
            foreach ($personIds as $id) {
                $result[$id][$table] = (int) ($counts[$id] ?? 0);
            }
        }
        $open = $this->database->table('person_departure_items')->where('organization_id', $organizationId)->whereIn('person_id', $personIds)
            ->where('status', 'open')->select('person_id')->selectRaw('COUNT(*) AS aggregate')->groupBy('person_id')->pluck('aggregate', 'person_id');
        foreach ($personIds as $id) {
            $result[$id]['open_departure_items'] = (int) ($open[$id] ?? 0);
        }
        $accounts = $this->database->table('person_external_accounts as a')->join('person_game_profiles as g', function (JoinClause $join): void {
            $join->on('g.id', '=', 'a.game_profile_id')->on('g.organization_id', '=', 'a.organization_id');
        })->where('a.organization_id', $organizationId)->whereIn('g.person_id', $personIds)
            ->select('g.person_id')->selectRaw('COUNT(*) AS aggregate')->groupBy('g.person_id')->pluck('aggregate', 'g.person_id');
        foreach ($personIds as $id) {
            $result[$id]['person_external_accounts'] = (int) ($accounts[$id] ?? 0);
        }

        return $result;
    }
}
