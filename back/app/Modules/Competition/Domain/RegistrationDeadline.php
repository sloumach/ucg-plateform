<?php

namespace App\Modules\Competition\Domain;

use Carbon\CarbonImmutable;

final class RegistrationDeadline
{
    /**
     * @param array<string,mixed> $item */
    public static function stage(array $item, CarbonImmutable $now): ?string
    {
        if ($item['status'] !== 'pending' || $item['due_at'] === null) {
            return null;
        }
        $due = CarbonImmutable::parse($item['due_at']);

        return $now->gte($due) ? 'overdue' : ($now->gte($due->subDay()) ? 'day' : ($now->gte($due->subDays(7)) ? 'week' : null));
    }

    /**
     * @param array<string,mixed> $item */
    public static function occurrence(string $competition, array $item, int $account, string $stage): string
    {
        return hash('sha256', implode('|', [$competition, $item['id'], $item['due_at'], $item['responsible_person_id'], $account, $stage]));
    }
}
