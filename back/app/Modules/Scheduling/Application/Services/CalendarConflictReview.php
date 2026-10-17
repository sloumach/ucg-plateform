<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Scheduling\Application\Data\CalendarConflictReport;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use JsonException;

final readonly class CalendarConflictReview
{
    public const int LIFETIME_SECONDS = 300;

    public function __construct(private Encrypter $encrypter) {}

    public function issue(CalendarConflictReport $report): string
    {
        return $this->encrypter->encrypt(json_encode(['expires' => now()->getTimestamp() + self::LIFETIME_SECONDS, 'fingerprint' => $this->fingerprint($report)], JSON_THROW_ON_ERROR), false);
    }

    public function verify(string $token, CalendarConflictReport $report): void
    {
        try {
            $payload = json_decode($this->encrypter->decrypt($token, false), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw $this->stale();
        }
        if (! is_array($payload) || ! is_int($payload['expires'] ?? null) || $payload['expires'] <= now()->getTimestamp()
            || ! is_string($payload['fingerprint'] ?? null) || ! hash_equals($this->fingerprint($report), $payload['fingerprint'])) {
            throw $this->stale();
        }
    }

    private function fingerprint(CalendarConflictReport $report): string
    {
        return hash('sha256', json_encode([$report->booking, $report->conflicts, $report->truncated, $report->availabilityChecked], JSON_THROW_ON_ERROR));
    }

    private function stale(): DomainConflictException
    {
        return new DomainConflictException(__('calendar.conflicts_changed'), 'CALENDAR_CONFLICTS_CHANGED');
    }
}
