<?php

namespace App\Modules\Media\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Contracts\MediaMatchDirectory;
use App\Modules\Media\Domain\Models\MediaContext;
use App\Modules\Media\Domain\Models\MediaItem;
use App\Modules\Scheduling\Application\Contracts\MediaCalendarDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Communication\MediaContextSource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

final readonly class MediaContexts
{
    public const array COLUMNS = MediaContext::COLUMNS;

    public function __construct(private MediaCalendarDirectory $calendar, private MediaMatchDirectory $matches) {}

    public function source(string $type): MediaContextSource
    {
        return match ($type) {
            'event' => $this->calendar, 'match' => $this->matches,
            default => throw new \InvalidArgumentException('Unsupported media context.'),
        };
    }

    /** @return array<string, array{visible: Builder|null, shared: Builder|null, scrim: Builder|null}> */
    public function constraints(TenantContext $context): array
    {
        $result = [];
        foreach (self::COLUMNS as $type => $column) {
            $result[$column] = $this->source($type)->constraints($context);
        }

        return $result;
    }

    /** @param array<string, mixed> $values
     * @return array{type: string, id: string, label: string, version: string, is_scrim: bool, visibility: string}|null */
    public function resolve(TenantContext $context, array $values, ?MediaItem $item): ?array
    {
        $binding = $item?->context;
        $type = $values['context_type'] ?? $binding?->type();
        $id = isset($values['context_id']) ? strtolower($values['context_id']) : $binding?->sourceId();
        if ($binding !== null && ($type !== $binding->type() || $id !== $binding->sourceId())) {
            throw ValidationException::withMessages(['context_id' => __('media.context_fixed')]);
        }
        if ($type === null) {
            if (isset($values['context_version'])) {
                throw ValidationException::withMessages(['context_version' => __('media.context_required')]);
            }

            return null;
        }
        if ($id === null) {
            throw ValidationException::withMessages(['context_id' => __('media.context_required')]);
        }
        $source = $this->source($type);
        $source->resolve($context, $id);
        $details = $source->metadata($context, [$id])[$id] ?? throw new ModelNotFoundException;
        if (! isset($values['context_version'])) {
            throw ValidationException::withMessages(['context_version' => __('media.context_version')]);
        }
        if ($details['version'] !== strtolower($values['context_version'])) {
            throw new DomainConflictException(__('media.context_stale'), 'MEDIA_CONTEXT_VERSION_STALE');
        }

        return ['type' => $type, ...$details];
    }

    /** @param list<MediaItem> $items */
    public function decorate(TenantContext $context, array $items): void
    {
        foreach (self::COLUMNS as $type => $column) {
            $ids = [];
            foreach ($items as $item) {
                if ($item->context?->type() === $type) {
                    $ids[] = $item->context->sourceId();
                }
            }
            if ($ids === []) {
                continue;
            }
            $details = $this->source($type)->metadata($context, array_values(array_unique($ids)));
            foreach ($items as $item) {
                if ($item->context?->type() === $type) {
                    $source = $details[$item->context->sourceId()] ?? throw new ModelNotFoundException;
                    $item->setAttribute('context_label', $source['label']);
                    $item->setAttribute('context_version', $source['version']);
                    $item->setAttribute('context_visibility', $source['visibility']);
                    $item->setAttribute('context_scrim', $source['is_scrim']);
                    $item->setAttribute('context_private', $source['visibility'] === 'private' || ($source['is_scrim'] && ! $item->is_scrim));
                }
            }
        }
    }
}
