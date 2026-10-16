<?php

namespace App\Modules\Media\Domain\Contracts;

use App\Modules\Media\Domain\CommunicationAudience;
use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Media\Domain\Models\AnnouncementComment;
use App\Modules\Media\Domain\Models\CommunicationEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CommunicationRepository
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Announcement>
     */
    public function paginate(string $org, CommunicationAudience $audience, array $filters, bool $management): LengthAwarePaginator;

    public function find(string $org, string $id, CommunicationAudience $audience, bool $management, bool $lock = false): Announcement;

    /** @param array<string, mixed> $attributes */
    public function save(?Announcement $record, array $attributes): Announcement;

    /** @return LengthAwarePaginator<int, AnnouncementComment> */
    public function comments(string $org, string $announcementId, int $page, int $perPage): LengthAwarePaginator;

    public function comment(Announcement $record, int $userId, string $body): AnnouncementComment;

    public function event(Announcement $record, int $userId, ?string $commentId = null): void;

    /** @return array{announcements_enabled: bool, comments_enabled: bool, version: string|null} */
    public function preferences(string $org, int $userId): array;

    /** @param array{announcements_enabled: bool, comments_enabled: bool, version: string} $values */
    public function savePreferences(string $org, int $userId, array $values): void;

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, CommunicationEvent>
     */
    public function inbox(string $org, int $userId, CommunicationAudience $audience, array $filters): LengthAwarePaginator;

    public function markRead(string $org, int $userId, string $eventId, CommunicationAudience $audience): void;
}
