<?php

namespace App\Modules\Competition\Domain;

final class MatchResultWorkflow
{
    public static function target(string $action, string $phase, ?string $previousAction, string $outcome): ?string
    {
        if (! in_array($outcome, ['played', 'forfeit', 'abandon'], true)) {
            return null;
        }

        return match ($action) {
            'submit' => ($previousAction === null || $previousAction === 'dispute')
                && in_array($phase, $outcome === 'played' ? ['in_progress', 'awaiting_validation', 'disputed'] : ['confirmed', 'in_progress', 'awaiting_validation', 'disputed'], true) ? 'awaiting_validation' : null,
            'approve' => $previousAction === 'submit' && $phase === 'awaiting_validation' ? ($outcome === 'forfeit' ? 'forfeited' : 'validated') : null,
            'dispute' => in_array($previousAction, ['submit', 'approve'], true) && in_array($phase, ['awaiting_validation', 'validated', 'forfeited'], true) ? 'disputed' : null,
            default => null,
        };
    }
}
