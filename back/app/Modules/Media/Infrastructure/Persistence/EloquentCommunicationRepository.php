<?php

namespace App\Modules\Media\Infrastructure\Persistence;

use App\Modules\Media\Domain\CommunicationAudience;
use App\Modules\Media\Domain\Contracts\CommunicationRepository;
use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Media\Domain\Models\AnnouncementComment;
use App\Modules\Media\Domain\Models\CommunicationEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Support\Str;

final readonly class EloquentCommunicationRepository implements CommunicationRepository
{
    public function __construct(private ConnectionInterface $database) {}

    public function paginate(string $org, CommunicationAudience $audience, array $filters, bool $management): LengthAwarePaginator
    {
        $q = $this->visible($org, $audience, $management);
        if (isset($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return $q->orderByDesc('created_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $org, string $id, CommunicationAudience $audience, bool $management, bool $lock = false): Announcement
    {
        $q = $this->visible($org, $audience, $management);

        return ($lock ? $q->lockForUpdate() : $q)->findOrFail(strtolower($id));
    }

    public function save(?Announcement $record, array $attributes): Announcement
    {
        $record ??= new Announcement;
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function comments(string $org, string $announcementId, int $page, int $perPage): LengthAwarePaginator
    {
        return AnnouncementComment::query()->where('organization_id', $org)->where('announcement_id', $announcementId)
            ->orderBy('created_at')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function comment(Announcement $record, int $userId, string $body): AnnouncementComment
    {
        $comment = new AnnouncementComment;
        $comment->forceFill(['organization_id' => $record->organization_id, 'announcement_id' => $record->id, 'created_by' => $userId, 'body' => $body])->save();

        return $comment;
    }

    public function event(Announcement $record, int $userId, ?string $commentId = null): void
    {
        $event = new CommunicationEvent;
        $event->forceFill(['organization_id' => $record->organization_id, 'announcement_id' => $record->id,
            'comment_id' => $commentId, 'kind' => $commentId === null ? 'announcement' : 'comment', 'actor_user_id' => $userId])->save();
    }

    public function preferences(string $org, int $userId): array
    {
        $p = $this->database->table('communication_preferences')->where('organization_id', $org)->where('user_id', $userId)->first();

        return ['announcements_enabled' => $p === null || (bool) $p->announcements_enabled,
            'comments_enabled' => $p !== null && (bool) $p->comments_enabled, 'version' => $p?->version];
    }

    public function savePreferences(string $org, int $userId, array $values): void
    {
        $query = $this->database->table('communication_preferences')->where('organization_id', $org)->where('user_id', $userId);
        if ($query->exists()) {
            $query->update([...$values, 'updated_at' => now()]);
        } else {
            $this->database->table('communication_preferences')->insert([...$values, 'organization_id' => $org, 'user_id' => $userId,
                'id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function inbox(string $org, int $userId, CommunicationAudience $audience, array $filters): LengthAwarePaginator
    {
        return $this->deliverable($org, $userId, $audience, (bool) ($filters['unread'] ?? false))
            ->with('announcement:id,title')->orderByDesc('communication_events.created_at')->orderBy('communication_events.id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['communication_events.*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function markRead(string $org, int $userId, string $eventId, CommunicationAudience $audience): void
    {
        $event = $this->deliverable($org, $userId, $audience, false)->findOrFail(strtolower($eventId));
        $this->database->table('communication_receipts')->insertOrIgnore(['id' => (string) Str::uuid(), 'organization_id' => $org,
            'user_id' => $userId, 'event_id' => $event->id, 'read_at' => now()]);
    }

    /** @return Builder<Announcement> */
    private function visible(string $org, CommunicationAudience $audience, bool $management = false): Builder
    {
        $q = Announcement::query()->where('organization_id', $org);
        if ($management) {
            return $q;
        }

        return $q->where('status', 'published')->where('published_at', '<=', now())
            ->where(fn (Builder $p) => $p->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn (Builder $a) => $a->where('target_type', 'organization')
                ->orWhere(fn (Builder $t) => $t->where('target_type', 'team')->whereIn('team_id', $audience->teams))
                ->orWhere(fn (Builder $g) => $g->where('target_type', 'game')->whereIn('game_id', $audience->games))
                ->orWhere(fn (Builder $r) => $r->where('target_type', 'role')->whereIn('role_id', $audience->roles)));
    }

    /** @return Builder<CommunicationEvent> */
    private function deliverable(string $org, int $userId, CommunicationAudience $audience, bool $unread): Builder
    {
        $preferences = $this->preferences($org, $userId);
        $read = fn (Query $q) => $q->selectRaw('1')->from('communication_receipts as receipt')
            ->where('receipt.organization_id', $org)->where('receipt.user_id', $userId)->whereColumn('receipt.event_id', 'communication_events.id');
        $q = CommunicationEvent::query()->where('communication_events.organization_id', $org)->where('actor_user_id', '!=', $userId)
            ->whereIn('announcement_id', $this->visible($org, $audience)->select('id'))
            ->where(function (Builder $events) use ($org, $userId, $preferences): void {
                $events->whereRaw('1 = 0');
                if ($preferences['announcements_enabled']) {
                    $events->orWhere('kind', 'announcement');
                }
                if ($preferences['comments_enabled']) {
                    $events->orWhere(fn (Builder $comments) => $comments->where('kind', 'comment')->where(fn (Builder $participants) => $participants
                        ->whereIn('announcement_id', Announcement::query()->where('organization_id', $org)->where('created_by', $userId)->select('id'))
                        ->orWhereExists(fn (Query $own) => $own->selectRaw('1')->from('announcement_comments as own_comment')
                            ->where('own_comment.organization_id', $org)->where('own_comment.created_by', $userId)
                            ->whereColumn('own_comment.announcement_id', 'communication_events.announcement_id')
                            ->whereColumn('own_comment.created_at', '<=', 'communication_events.created_at'))));
                }
            });
        $q->select('communication_events.*')->selectSub($this->database->table('communication_receipts')->select('read_at')
            ->where('organization_id', $org)->where('user_id', $userId)->whereColumn('event_id', 'communication_events.id')->limit(1), 'read_at');
        if ($unread) {
            $q->whereNotExists($read);
        }

        return $q;
    }
}
