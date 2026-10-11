<?php

namespace App\Support\Audit;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class AuditTimelineQuery
{
    public function __construct(private DatabaseManager $database, private Encrypter $cipher) {}

    /** @param list<AuditSource> $sources */
    public function page(array $sources, AuditFilter $filter, ?string $organizationId = null): AuditPage
    {
        if ($filter->perPage < 1 || $filter->perPage > 100 || $filter->auditorId < 1) {
            throw ValidationException::withMessages(['per_page' => __('audit.invalid_filter')]);
        }
        $scope = $filter->scope($organizationId);
        $cursor = $this->decode($filter->cursor, $scope);
        $entries = [];
        foreach ($sources as $source) {
            if ($filter->source !== null && $filter->source !== $source->name) {
                continue;
            }
            if ($source->tenantOwned !== ($organizationId !== null)) {
                throw new \LogicException('An audit source must match its server-resolved scope.');
            }
            $query = $this->database->table($source->table)->select($source->columns);
            if ($organizationId !== null) {
                $query->where('organization_id', $organizationId);
            }
            if ($filter->action !== null) {
                if ($source->fixedAction === null) {
                    $query->where('action', $filter->action);
                } elseif ($source->fixedAction !== $filter->action) {
                    $query->whereRaw('1 = 0');
                }
            }
            if ($filter->actorId !== null) {
                $query->where($source->actorColumn, $filter->actorId);
                if ($source->knownActorActions !== null) {
                    $query->whereIn('action', $source->knownActorActions);
                }
            }
            if ($filter->from !== null) {
                $query->where('occurred_at', '>=', $filter->from);
            }
            if ($filter->until !== null) {
                $query->where('occurred_at', '<=', $filter->until);
            }
            if ($cursor !== null) {
                $this->after($query, $source->name, $cursor);
            }
            foreach ($query->orderByDesc('occurred_at')->orderByDesc('id')->limit($filter->perPage + 1)->get() as $row) {
                $entries[] = $source->project($row);
            }
        }
        usort($entries, static fn (AuditEntry $a, AuditEntry $b): int => [$b->occurredAt, $b->source, $b->id] <=> [$a->occurredAt, $a->source, $a->id]);
        $hasMore = count($entries) > $filter->perPage;
        $entries = array_slice($entries, 0, $filter->perPage);
        $last = $entries === [] ? null : $entries[array_key_last($entries)];
        $next = $hasMore && $last !== null ? $this->cipher->encrypt(json_encode([
            'scope' => $scope, 'at' => $last->databaseTime, 'source' => $last->source, 'id' => $last->id], JSON_THROW_ON_ERROR), false) : null;

        return new AuditPage($entries, $next, $filter->perPage);
    }

    /** @param array{at:string,source:string,id:string} $cursor */
    private function after(Builder $query, string $source, array $cursor): void
    {
        // Equal timestamps must follow the same source/UUID order as the merged page.
        $query->where(function (Builder $query) use ($source, $cursor): void {
            $query->where('occurred_at', '<', $cursor['at']);
            if ($source < $cursor['source']) {
                $query->orWhere('occurred_at', $cursor['at']);
            } elseif ($source === $cursor['source']) {
                $query->orWhere(fn (Builder $query): Builder => $query->where('occurred_at', $cursor['at'])->where('id', '<', $cursor['id']));
            }
        });
    }

    /** @return array{at:string,source:string,id:string}|null */
    private function decode(?string $token, string $scope): ?array
    {
        if ($token === null) {
            return null;
        }
        try {
            $json = $this->cipher->decrypt($token, false);
            if (! is_string($json)) {
                throw new \InvalidArgumentException;
            }
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['scope'] ?? null) !== $scope || ! is_string($data['at'] ?? null)
                || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}(?::\d{2})?)?$/D', $data['at']) !== 1
                || ! is_string($data['source'] ?? null) || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])) {
                throw new \InvalidArgumentException;
            }

            return ['at' => $data['at'], 'source' => $data['source'], 'id' => $data['id']];
        } catch (Throwable $exception) {
            if (! ($exception instanceof DecryptException) && ! ($exception instanceof \JsonException) && ! ($exception instanceof \InvalidArgumentException)) {
                throw $exception;
            }
            throw ValidationException::withMessages(['cursor' => __('audit.invalid_cursor')]);
        }
    }
}
