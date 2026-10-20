<?php

namespace App\Modules\Competition\Domain\Contracts;

use App\Modules\Competition\Domain\Models\CompetitionRegistrationReminder;
use App\Modules\Competition\Domain\Models\CompetitionRegistrationRevision;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CompetitionRegistrationRepository
{
    public function dashboardCount(string $org, string $from, string $to): int;

    /** @return LengthAwarePaginator<int,CompetitionRegistrationRevision> */
    public function dashboardDue(string $org, string $from, string $to, int $page): LengthAwarePaginator;

    public function latest(string $org, string $competition): ?CompetitionRegistrationRevision;

    /**
     * @param array<string,mixed> $attributes */
    public function append(array $attributes): CompetitionRegistrationRevision;

    /**
     * @return LengthAwarePaginator<int,CompetitionRegistrationRevision> */
    public function history(string $org, string $competition, int $page, int $size): LengthAwarePaginator;

    /**
     * @return list<CompetitionRegistrationRevision> */
    public function sweep(string $org, CarbonImmutable $until): array;

    public function checked(CompetitionRegistrationRevision $revision): void;

    /**
     * @param array<string,mixed> $attributes */
    public function remind(array $attributes): void;

    /**
     * @return LengthAwarePaginator<int,CompetitionRegistrationReminder> */
    public function reminders(string $org, string $competition, int $actor, int $page, int $size): LengthAwarePaginator;

    /**
     * @param list<string> $versions
     * @return list<string> */
    public function documentVersions(string $org, string $competition, array $versions): array;
}
