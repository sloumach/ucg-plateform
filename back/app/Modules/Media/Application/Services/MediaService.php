<?php

namespace App\Modules\Media\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Media\Application\Validation\MediaInput;
use App\Modules\Media\Domain\Contracts\MediaRepository;
use App\Modules\Media\Domain\MediaLink;
use App\Modules\Media\Domain\Models\MediaItem;
use App\Modules\Teams\Application\Contracts\MediaTeamsDirectory;
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
use InvalidArgumentException;

final readonly class MediaService
{
    public function __construct(private MediaRepository $records, private MediaTeamsDirectory $teams,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private TenantAuditJournal $audit,
        private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, MediaItem>
     */
    public function paginate(TenantContext $context, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'media.read');
        $filters = MediaInput::validate($this->validator, $filters, MediaInput::pageRules());
        if ($filters['archived'] ?? false) {
            $this->authorization->authorize($context, 'media.write');
        }
        $page = $this->records->paginate($context->organizationId, $filters,
            $this->authorization->allows($context, 'media.write'));
        $this->label($context, array_values($page->items()));

        return $page;
    }

    public function find(TenantContext $context, string $id): MediaItem
    {
        $this->authorization->authorize($context, 'media.read');
        $record = $this->records->find($context->organizationId, $id, $this->authorization->allows($context, 'media.write'));
        $this->label($context, [$record]);

        return $record;
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, array{id: string, label: string}>
     */
    public function teams(TenantContext $context, array $filters): LengthAwarePaginator
    {
        $values = MediaInput::validate($this->validator, $filters, MediaInput::pageRules(true));

        return $this->teams->paginate($context, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10));
    }

    /** @param array<string, mixed> $input */
    public function save(TenantContext $context, array $input, ?string $id = null, ?string $ipAddress = null): MediaItem
    {
        $this->authorizeWrite($context);
        $values = MediaInput::validate($this->validator, $input, MediaInput::saveRules($id === null));
        if ($id !== null && array_diff(array_keys($values), ['version', 'confirm_sharing']) === []) {
            throw ValidationException::withMessages(['record' => __('media.empty')]);
        }
        $saved = $this->database->transaction(function () use ($context, $values, $id, $ipAddress): MediaItem {
            $this->guard->lock($context);
            $this->authorizeWrite($context);
            $record = $id === null ? null : $this->records->find($context->organizationId, $id, true, true);
            if ($record !== null && $record->version !== strtolower((string) $values['version'])) {
                throw new DomainConflictException(__('media.stale'), 'MEDIA_VERSION_STALE');
            }
            if ($record?->archived_at !== null) {
                throw new DomainConflictException(__('media.archived'), 'MEDIA_ARCHIVED');
            }
            $attributes = $this->attributes($context, $values, $record);
            $saved = $this->records->save($record, $attributes);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
                AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ipAddress,
                [], ['media_id' => $saved->id, 'media_action' => isset($values['archive']) ? 'archived' : ($id === null ? 'created' : 'updated')]));

            return $saved;
        });
        $this->label($context, [$saved]);

        return $saved;
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function attributes(TenantContext $context, array $values, ?MediaItem $record): array
    {
        if (isset($values['archive'])) {
            if ($record === null || array_diff(array_keys($values), ['version', 'archive']) !== []) {
                throw ValidationException::withMessages(['archive' => __('media.archive_only')]);
            }

            return ['archived_at' => now(), 'version' => (string) Str::uuid()];
        }
        $attributes = $values;
        unset($attributes['version'], $attributes['confirm_sharing']);
        foreach (['title', 'url', 'provider', 'kind', 'team_id'] as $field) {
            if (isset($attributes[$field]) && is_string($attributes[$field])) {
                $attributes[$field] = trim($attributes[$field]);
            }
        }
        if (isset($attributes['team_id'])) {
            $attributes['team_id'] = strtolower($attributes['team_id']);
        }
        $combined = [...($record?->getAttributes() ?? ['visibility' => 'private', 'is_scrim' => false, 'publish_at' => null]), ...$attributes];
        $this->teams->assertActive($context, $combined['team_id']);
        try {
            $attributes['url'] = MediaLink::canonical($combined['provider'], $combined['kind'], $combined['url']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['url' => __('media.url')]);
        }
        $status = $combined['status'] ?? ($combined['kind'] === 'stream' ? 'scheduled' : 'ended');
        if ($combined['kind'] !== 'stream' && ! in_array($status, ['ended', 'unavailable'], true)) {
            throw ValidationException::withMessages(['status' => __('media.recorded_status')]);
        }
        if ($record !== null && array_intersect(array_keys($values), ['status', 'url', 'provider', 'kind']) !== [] && ! isset($values['status_observed_at'])) {
            throw ValidationException::withMessages(['status_observed_at' => __('media.observation_required')]);
        }
        if ($record !== null && isset($values['status_observed_at']) && CarbonImmutable::parse($values['status_observed_at'])->lt($record->status_observed_at)) {
            throw ValidationException::withMessages(['status_observed_at' => __('media.observation_old')]);
        }
        $sharing = $combined['visibility'] !== 'private';
        if ($sharing) {
            $this->authorization->authorize($context, 'media.publish');
            if (! isset($values['confirm_sharing'])) {
                throw ValidationException::withMessages(['confirm_sharing' => __('media.confirm')]);
            }
            if ($combined['is_scrim'] && $combined['publish_at'] === null) {
                throw ValidationException::withMessages(['publish_at' => __('media.scrim_delay')]);
            }
            if ($combined['is_scrim'] && ($record === null || $record->visibility === 'private' || ! $record->is_scrim || array_key_exists('publish_at', $values))
                && ! CarbonImmutable::parse($combined['publish_at'])->isFuture()) {
                throw ValidationException::withMessages(['publish_at' => __('media.scrim_delay')]);
            }
            $attributes['sharing_authorized_by'] = $context->actorUserId;
            $attributes['sharing_authorized_at'] = now();
        } elseif (isset($attributes['publish_at'])) {
            throw ValidationException::withMessages(['publish_at' => __('media.private_delay')]);
        }
        if ($combined['visibility'] === 'private') {
            $attributes['publish_at'] = null;
            $attributes['sharing_authorized_by'] = null;
            $attributes['sharing_authorized_at'] = null;
        }

        return [...($record === null ? ['organization_id' => $context->organizationId, 'created_by' => $context->actorUserId,
            'visibility' => 'private', 'is_scrim' => false, 'status' => $status, 'status_observed_at' => now()] : []),
            ...$attributes, 'version' => (string) Str::uuid()];
    }

    private function authorizeWrite(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'media.read');
        $this->authorization->authorize($context, 'media.write');
    }

    /** @param list<MediaItem> $records */
    private function label(TenantContext $context, array $records): void
    {
        $labels = $this->teams->labels($context, array_values(array_unique(array_map(fn (MediaItem $item): string => $item->team_id, $records))));
        foreach ($records as $record) {
            $record->setAttribute('team_label', $labels[$record->team_id] ?? null);
        }
    }
}
