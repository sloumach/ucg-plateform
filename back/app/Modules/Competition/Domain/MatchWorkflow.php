<?php

namespace App\Modules\Competition\Domain;

final class MatchWorkflow
{
    public const array STATUSES = ['proposed', 'confirmed', 'in_progress', 'awaiting_validation', 'validated', 'disputed', 'cancelled', 'forfeited'];

    /** A result status is reached only through the future score/proof workflow. */
    public const array RESULT_STATUSES = ['validated', 'disputed', 'forfeited'];

    /** @return list<string> */
    public static function next(string $status): array
    {
        return match ($status) {
            'proposed' => ['confirmed', 'cancelled'],
            'confirmed' => ['proposed', 'in_progress', 'cancelled', 'forfeited'],
            'in_progress' => ['awaiting_validation', 'cancelled', 'forfeited'],
            'awaiting_validation' => ['validated', 'disputed'],
            'validated' => ['disputed'],
            'disputed' => ['awaiting_validation', 'validated', 'cancelled'],
            default => [],
        };
    }

    public static function permits(string $from, string $to): bool
    {
        return in_array($to, self::next($from), true);
    }

    /** @return list<string> */
    public static function operational(string $status): array
    {
        if (in_array($status, self::RESULT_STATUSES, true)) {
            return [];
        }

        return array_values(array_diff(self::next($status), self::RESULT_STATUSES));
    }

    public static function participantCount(string $format, int $count): bool
    {
        return in_array($format, ['bo1', 'bo3', 'bo5'], true) ? $count === 2 : in_array($format, ['lobby', 'free_for_all'], true) && $count >= 2 && $count <= 100;
    }
}
