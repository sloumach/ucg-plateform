<?php

namespace App\Modules\Media\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Media\Application\Validation\CommunicationInput;
use App\Modules\Media\Domain\CommunicationAudience;
use App\Modules\Media\Domain\Contracts\CommunicationRepository;
use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Media\Domain\Models\AnnouncementComment;
use App\Modules\Media\Domain\Models\CommunicationEvent;
use App\Modules\Teams\Application\Contracts\CommunicationTeamsDirectory;
use App\Modules\Tenancy\Application\Contracts\CommunicationRolesDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class CommunicationService
{
    public function __construct(private CommunicationRepository $records, private CommunicationTeamsDirectory $teams,
        private CommunicationRolesDirectory $roles, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $input
     * @return LengthAwarePaginator<int, Announcement>
     */
    public function paginate(TenantContext $context, array $input): LengthAwarePaginator
    {
        $values = $this->validated($context, $input, 'index');
        $management = $this->management($context, $values);
        if (isset($values['status']) && ! $management) {
            throw ValidationException::withMessages(['status' => __('communication.management_required')]);
        }
        $audience = $this->audience($context);
        $page = $this->records->paginate($context->organizationId, $audience, $values, $management);
        $this->decorate($context, array_values($page->items()), $audience);

        return $page;
    }

    /** @param array<string, mixed> $input */
    public function find(TenantContext $context, string $id, array $input): Announcement
    {
        $values = $this->validated($context, $input, 'show');
        $audience = $this->audience($context);
        $record = $this->records->find($context->organizationId, $id, $audience, $this->management($context, $values));
        $this->decorate($context, [$record], $audience);

        return $record;
    }

    /** @param array<string, mixed> $input
     * @return LengthAwarePaginator<int, array{id: string, label: string}>
     */
    public function targets(TenantContext $context, array $input): LengthAwarePaginator
    {
        $values = $this->validated($context, $input, 'targets');
        $this->write($context);
        $page = (int) ($values['page'] ?? 1);
        $perPage = (int) ($values['per_page'] ?? 10);

        return $values['type'] === 'role' ? $this->roles->choices($context, $page, $perPage)
            : $this->teams->choices($context, $values['type'], $page, $perPage);
    }

    /** @param array<string, mixed> $input */
    public function save(TenantContext $context, array $input, ?string $id = null, ?string $ip = null): Announcement
    {
        $this->write($context);
        $values = $this->validated($context, $input, $id === null ? 'store' : 'update');
        $record = $this->database->transaction(function () use ($context, $id, $values, $ip): Announcement {
            $this->guard->lock($context);
            $this->write($context);
            $record = $id === null ? null : $this->records->find($context->organizationId, $id, new CommunicationAudience([], [], []), true, true);
            if ($record !== null) {
                $this->version($record->version, $values['version']);
            }
            if ($record?->status === 'withdrawn') {
                throw new DomainConflictException(__('communication.withdrawn'), 'COMMUNICATION_WITHDRAWN');
            }
            $action = $values['action'] ?? ($id === null ? 'created' : 'updated');
            $attributes = isset($values['action']) ? $this->transition($context, $record, $values) : $this->draft($context, $record, $values);
            $saved = $this->records->save($record, [...$attributes, 'version' => (string) Str::uuid()]);
            if ($action === 'publish' && ($values['notify_members'] ?? false)) {
                $this->records->event($saved, $context->actorUserId);
            }
            $this->audit($context, $saved->id, $action, $ip);

            return $saved;
        });
        $this->decorate($context, [$record], $this->audience($context));

        return $record;
    }

    /** @param array<string, mixed> $input
     * @return LengthAwarePaginator<int, AnnouncementComment>
     */
    public function comments(TenantContext $context, string $id, array $input): LengthAwarePaginator
    {
        $values = $this->validated($context, $input, 'comments');
        $record = $this->records->find($context->organizationId, $id, $this->audience($context), $this->management($context, $values));
        $page = $this->records->comments($context->organizationId, $record->id, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10));
        foreach ($page->items() as $comment) {
            $comment->setAttribute('is_mine', $comment->created_by === $context->actorUserId);
        }

        return $page;
    }

    /** @param array<string, mixed> $input */
    public function comment(TenantContext $context, string $id, array $input, ?string $ip = null): AnnouncementComment
    {
        $values = $this->validated($context, $input, 'comment');
        $this->authorization->authorize($context, 'communication.comment');
        $saved = $this->database->transaction(function () use ($context, $id, $values, $ip): AnnouncementComment {
            $this->guard->lock($context);
            $this->authorization->authorize($context, 'communication.read');
            $this->authorization->authorize($context, 'communication.comment');
            // Management access never grants permission to participate outside the current audience.
            $record = $this->records->find($context->organizationId, $id, $this->audience($context), false, true);
            $this->version($record->version, $values['version']);
            if (! $record->comments_enabled) {
                throw new DomainConflictException(__('communication.comments_closed'), 'COMMUNICATION_COMMENTS_CLOSED');
            }
            $comment = $this->records->comment($record, $context->actorUserId, trim($values['body']));
            if ($values['notify_members'] ?? false) {
                $this->records->event($record, $context->actorUserId, $comment->id);
            }
            $this->audit($context, $record->id, 'comment', $ip);

            return $comment;
        });
        $saved->setAttribute('is_mine', true);

        return $saved;
    }

    /** @return array{announcements_enabled: bool, comments_enabled: bool, version: string|null} */
    public function preferences(TenantContext $context): array
    {
        $this->authorization->authorize($context, 'communication.read');

        return $this->records->preferences($context->organizationId, $context->actorUserId);
    }

    /** @param array<string, mixed> $input
     * @return array{announcements_enabled: bool, comments_enabled: bool, version: string|null}
     */
    public function savePreferences(TenantContext $context, array $input, ?string $ip = null): array
    {
        $values = $this->validated($context, $input, 'savePreferences');

        return $this->database->transaction(function () use ($context, $values, $ip): array {
            $this->guard->lock($context);
            $previous = $this->preferences($context);
            $this->version($previous['version'], $values['version']);
            $this->records->savePreferences($context->organizationId, $context->actorUserId,
                ['announcements_enabled' => (bool) $values['announcements_enabled'], 'comments_enabled' => (bool) $values['comments_enabled'], 'version' => (string) Str::uuid()]);
            $this->audit($context, null, 'preferences', $ip);

            return $this->preferences($context);
        });
    }

    /** @param array<string, mixed> $input
     * @return LengthAwarePaginator<int, CommunicationEvent>
     */
    public function inbox(TenantContext $context, array $input): LengthAwarePaginator
    {
        $values = $this->validated($context, $input, 'inbox');

        return $this->records->inbox($context->organizationId, $context->actorUserId, $this->audience($context), $values);
    }

    public function markRead(TenantContext $context, string $eventId): void
    {
        $this->authorization->authorize($context, 'communication.read');
        $this->database->transaction(function () use ($context, $eventId): void {
            $this->guard->lock($context);
            $this->authorization->authorize($context, 'communication.read');
            $this->records->markRead($context->organizationId, $context->actorUserId, $eventId, $this->audience($context));
        });
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function draft(TenantContext $context, ?Announcement $record, array $values): array
    {
        if ($record !== null && $record->status !== 'draft') {
            throw new DomainConflictException(__('communication.frozen'), 'COMMUNICATION_CONTENT_FROZEN');
        }
        if (array_diff(array_keys($values), ['version']) === [] || array_intersect(array_keys($values), ['confirm_publication', 'notify_members']) !== []) {
            throw ValidationException::withMessages(['record' => __('communication.empty')]);
        }
        $type = $values['target_type'] ?? ($record === null ? 'organization' : $record->target_type);
        $id = array_key_exists('target_id', $values) ? $values['target_id'] : (isset($values['target_type']) ? null : $record?->targetId());
        $id = is_string($id) ? strtolower($id) : null;
        if (($type === 'organization') !== ($id === null)) {
            throw ValidationException::withMessages(['target_id' => __('communication.target')]);
        }
        $this->assertTarget($context, $type, $id);
        $attributes = array_intersect_key($values, array_flip(['title', 'body', 'comments_enabled', 'expires_at']));
        foreach (['title', 'body'] as $key) {
            if (isset($attributes[$key])) {
                $attributes[$key] = trim($attributes[$key]);
            }
        }

        return [...($record === null ? ['organization_id' => $context->organizationId, 'created_by' => $context->actorUserId, 'status' => 'draft'] : []),
            ...$attributes, 'target_type' => $type, 'team_id' => $type === 'team' ? $id : null,
            'game_id' => $type === 'game' ? $id : null, 'role_id' => $type === 'role' ? $id : null];
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function transition(TenantContext $context, ?Announcement $record, array $values): array
    {
        if ($record === null) {
            throw ValidationException::withMessages(['action' => __('communication.draft_required')]);
        }
        $action = $values['action'];
        $allowed = match ($action) {
            'publish' => ['version', 'action', 'confirm_publication', 'notify_members'], 'comments' => ['version', 'action', 'comments_enabled'], default => ['version', 'action']
        };
        if (array_diff(array_keys($values), $allowed) !== []) {
            throw ValidationException::withMessages(['action' => __('communication.action_only')]);
        }
        if ($action === 'publish') {
            $this->authorization->authorize($context, 'communication.publish');
            if ($record->status !== 'draft') {
                throw new DomainConflictException(__('communication.draft_required'), 'COMMUNICATION_TRANSITION_INVALID');
            }
            $this->assertTarget($context, $record->target_type, $record->targetId());
            if ($record->expires_at !== null && ! CarbonImmutable::instance($record->expires_at)->isFuture()) {
                throw ValidationException::withMessages(['expires_at' => __('communication.future')]);
            }

            return ['status' => 'published', 'published_at' => now(), 'published_by' => $context->actorUserId];
        }
        if ($record->status !== 'published') {
            throw new DomainConflictException(__('communication.published_required'), 'COMMUNICATION_TRANSITION_INVALID');
        }
        if ($action === 'withdraw') {
            $this->authorization->authorize($context, 'communication.publish');

            return ['status' => 'withdrawn', 'withdrawn_at' => now()];
        }
        if (! array_key_exists('comments_enabled', $values)) {
            throw ValidationException::withMessages(['comments_enabled' => __('communication.required')]);
        }

        return ['comments_enabled' => (bool) $values['comments_enabled']];
    }

    private function assertTarget(TenantContext $context, string $type, ?string $id): void
    {
        if ($type === 'role' && $id !== null) {
            $this->roles->assertActive($context, $id);
        }
        if (in_array($type, ['team', 'game'], true) && $id !== null) {
            $this->teams->assertActive($context, $type, $id);
        }
    }

    private function audience(TenantContext $context): CommunicationAudience
    {
        $sporting = $this->teams->current($context);

        return new CommunicationAudience($sporting['teams'], $sporting['games'], $this->roles->current($context));
    }

    /** @param array<string, mixed> $values */
    private function management(TenantContext $context, array $values): bool
    {
        if ($values['management'] ?? false) {
            $this->write($context);

            return true;
        }

        return false;
    }

    private function write(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'communication.read');
        $this->authorization->authorize($context, 'communication.write');
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function validated(TenantContext $context, array $values, string $operation): array
    {
        $this->authorization->authorize($context, 'communication.read');

        return CommunicationInput::validate($this->validator, $values, $operation);
    }

    private function version(?string $actual, ?string $expected): void
    {
        if ($actual !== ($expected === null ? null : strtolower($expected))) {
            throw new DomainConflictException(__('communication.stale'), 'COMMUNICATION_VERSION_STALE');
        }
    }

    /** @param list<Announcement> $records */
    private function decorate(TenantContext $context, array $records, CommunicationAudience $audience): void
    {
        $commenter = $this->authorization->allows($context, 'communication.comment');
        foreach ($records as $record) {
            $record->setAttribute('can_comment', $commenter && $record->comments_enabled && $record->status === 'published'
                && ($record->expires_at === null || $record->expires_at->isFuture()) && $audience->includes($record->target_type, $record->targetId()));
        }
    }

    private function audit(TenantContext $context, ?string $id, string $action, ?string $ip): void
    {
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
            $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['communication_id' => $id, 'communication_action' => $action]));
    }
}
