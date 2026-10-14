<?php

namespace Tests\Unit;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Domain\ContractLifecycle;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContractLifecycleTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function invalidTransitions(): array
    {
        return ['draft signature' => ['draft', 'signed'], 'approved active' => ['approved', 'active'],
            'active draft' => ['active', 'draft'], 'archive restore' => ['archived', 'draft'], 'signed review' => ['signed', 'in_review']];
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transitions_cannot_skip_or_rewrite_the_workflow(string $from, string $to): void
    {
        $this->expectException(DomainConflictException::class);
        ContractLifecycle::assertTransition($from, $to, '2026-01-01', '2026-12-31', CarbonImmutable::parse('2026-10-14'), 0);
    }

    /** @return array<string, array{string, ?string, ?string, bool}> */
    public static function forceCases(): array
    {
        return ['signed future' => ['signed', '2026-11-01', null, false], 'signed current' => ['signed', '2026-01-01', null, false],
            'active current' => ['active', '2026-01-01', null, true], 'inclusive end' => ['active', '2026-01-01', '2026-10-14', true],
            'expired active' => ['active', '2026-01-01', '2026-10-13', false], 'future active' => ['active', '2026-10-15', null, false],
            'terminated' => ['terminated', '2026-01-01', null, false]];
    }

    #[DataProvider('forceCases')]
    public function test_signature_and_current_period_do_not_imply_active_status(string $status, ?string $start, ?string $end, bool $expected): void
    {
        $this->assertSame($expected, ContractLifecycle::inForce($status, $start, $end, CarbonImmutable::parse('2026-10-14')));
    }

    public function test_archival_is_blocked_by_pending_obligations(): void
    {
        $this->expectException(DomainConflictException::class);
        ContractLifecycle::assertTransition('terminated', 'archived', '2026-01-01', '2026-12-31', CarbonImmutable::parse('2026-10-14'), 1);
    }

    public function test_an_open_ended_contract_cannot_be_recorded_as_naturally_ended(): void
    {
        $this->expectException(DomainConflictException::class);
        ContractLifecycle::assertTransition('active', 'ended', '2026-01-01', null, CarbonImmutable::parse('2026-10-14'), 0);
    }

    public function test_the_last_inclusive_day_is_not_yet_the_end_of_term(): void
    {
        $this->expectException(DomainConflictException::class);
        ContractLifecycle::assertTransition('active', 'ended', '2026-01-01', '2026-10-14', CarbonImmutable::parse('2026-10-14'), 0);
    }
}
