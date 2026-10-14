<?php

namespace App\Modules\Contracts\Domain;

use App\Exceptions\DomainConflictException;
use Carbon\CarbonImmutable;

final class ContractLifecycle
{
    public const array STATUSES = ['draft', 'in_review', 'approved', 'sent', 'signed', 'active', 'ended', 'terminated', 'archived'];

    public const array TYPES = ['player', 'coach', 'manager', 'analyst', 'contributor', 'sponsor'];

    public const array TRANSITIONS = [
        'draft' => ['in_review', 'archived'], 'in_review' => ['draft', 'approved', 'archived'],
        'approved' => ['in_review', 'sent'], 'sent' => ['in_review', 'signed'],
        'signed' => ['active', 'ended', 'terminated'], 'active' => ['ended', 'terminated'],
        'ended' => ['archived'], 'terminated' => ['archived'], 'archived' => [],
    ];

    public const array VALIDATION_TARGETS = ['approved', 'signed', 'active', 'ended', 'terminated'];

    public static function editable(string $status): bool
    {
        return in_array($status, ['draft', 'in_review'], true);
    }

    public static function assertTransition(string $from, string $to, ?string $start, ?string $end, CarbonImmutable $today, int $pending): void
    {
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new DomainConflictException(__('managed_contracts.transition'), 'CONTRACT_TRANSITION_INVALID');
        }
        $day = $today->utc()->format('Y-m-d');
        if (in_array($to, ['approved', 'sent', 'signed', 'active'], true) && $start === null) {
            throw new DomainConflictException(__('managed_contracts.dates_required'), 'CONTRACT_DATES_REQUIRED');
        }
        if ($to === 'active' && ($start > $day || ($end !== null && $end < $day))) {
            throw new DomainConflictException(__('managed_contracts.not_effective'), 'CONTRACT_NOT_EFFECTIVE');
        }
        if ($to === 'ended' && ($end === null || $end >= $day)) {
            throw new DomainConflictException(__('managed_contracts.not_ended'), 'CONTRACT_TERM_NOT_ENDED');
        }
        if ($to === 'archived' && $pending > 0) {
            throw new DomainConflictException(__('managed_contracts.pending'), 'CONTRACT_PENDING_OBLIGATIONS');
        }
    }

    public static function inForce(string $status, ?string $start, ?string $end, CarbonImmutable $today): bool
    {
        $day = $today->utc()->format('Y-m-d');

        return $status === 'active' && $start !== null && $start <= $day && ($end === null || $end >= $day);
    }
}
