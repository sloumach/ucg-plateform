<?php

namespace App\Modules\Teams\Domain\Contracts;

use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use App\Modules\Teams\Domain\Models\TeamLineupEligibilityReport;
use App\Modules\Teams\Domain\Models\TeamLineupRuleBinding;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TeamEligibilityRepository
{
    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,TeamEligibilityRuleset>
     */
    public function rules(string $org, array $filters): LengthAwarePaginator;

    public function findRule(string $org, string $id, bool $lock = false): TeamEligibilityRuleset;

    public function game(string $id): GameCatalogueEntry;

    public function countRules(string $org): int;

    /** @param array<string,mixed> $values */
    public function saveRule(?TeamEligibilityRuleset $rule, array $values): TeamEligibilityRuleset;

    public function binding(string $org, string $lineupId): ?TeamLineupRuleBinding;

    /** @param array<string,mixed> $values */
    public function bind(array $values): TeamLineupRuleBinding;

    /** @return LengthAwarePaginator<int,TeamLineupEligibilityReport> */
    public function reports(string $org, string $lineupId, int $perPage, int $page): LengthAwarePaginator;

    public function countReports(string $org, string $lineupId): int;

    /** @param array<string,mixed> $values */
    public function report(array $values): TeamLineupEligibilityReport;
}
