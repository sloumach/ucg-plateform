<?php

namespace App\Modules\Partnerships\Infrastructure\Persistence;

use App\Modules\Partnerships\Domain\Contracts\SponsorRepository;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Domain\Models\SponsorContact;
use App\Modules\Partnerships\Domain\Models\SponsorshipInteraction;
use App\Modules\Partnerships\Domain\Models\SponsorshipOpportunity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final readonly class EloquentSponsorRepository implements SponsorRepository
{
    public function paginate(string $organizationId, array $filters): LengthAwarePaginator
    {
        $query = Sponsor::query()->where('organization_id', strtolower($organizationId));
        $this->filter($query->getQuery(), $filters, 'name');

        return $query->orderBy('name')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $organizationId, string $id, bool $lock = false): Sponsor
    {
        $query = Sponsor::query()->where('organization_id', strtolower($organizationId));

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(strtolower($id));
    }

    public function save(?Sponsor $sponsor, array $attributes): Sponsor
    {
        $sponsor ??= new Sponsor;
        $sponsor->forceFill($attributes)->save();

        return $sponsor;
    }

    public function related(string $organizationId, string $sponsorId, string $kind, array $filters): LengthAwarePaginator
    {
        $query = $this->model($kind)->newQuery()->where('organization_id', strtolower($organizationId))->where('sponsor_id', strtolower($sponsorId));
        $column = match ($kind) {
            'contacts' => 'name', 'opportunities' => 'title', default => 'summary'
        };
        $this->filter($query->getQuery(), $filters, $column);
        if ($kind === 'interactions') {
            $query->orderByDesc('occurred_at')->orderByDesc('created_at');
        } else {
            $query->orderBy($column);
        }

        return $query->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function findRelated(string $organizationId, string $sponsorId, string $kind, string $id): SponsorContact|SponsorshipOpportunity
    {
        if ($kind === 'interactions') {
            throw new InvalidArgumentException('Interactions are append-only.');
        }

        /** @var SponsorContact|SponsorshipOpportunity */
        return $this->model($kind)->newQuery()->where('organization_id', strtolower($organizationId))
            ->where('sponsor_id', strtolower($sponsorId))->lockForUpdate()->findOrFail(strtolower($id));
    }

    public function saveRelated(string $kind, SponsorContact|SponsorshipOpportunity|null $record, array $attributes): SponsorContact|SponsorshipOpportunity|SponsorshipInteraction
    {
        $record ??= $this->model($kind);
        $record->forceFill($attributes)->save();

        return $record;
    }

    private function model(string $kind): SponsorContact|SponsorshipOpportunity|SponsorshipInteraction
    {
        return match ($kind) {
            'contacts' => new SponsorContact, 'opportunities' => new SponsorshipOpportunity,
            'interactions' => new SponsorshipInteraction, default => throw new InvalidArgumentException('Unknown partnership record type.'),
        };
    }

    /** @param array<string, mixed> $filters */
    private function filter(Builder $query, array $filters, string $column): void
    {
        foreach (['status', 'channel', 'contact_id', 'opportunity_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, str_ends_with($field, '_id') ? strtolower($filters[$field]) : $filters[$field]);
            }
        }
        if (isset($filters['q'])) {
            $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filters['q'])));
            $sql = match ($column) {
                'name' => "LOWER(name) LIKE ? ESCAPE '!'", 'title' => "LOWER(title) LIKE ? ESCAPE '!'",
                default => "LOWER(summary) LIKE ? ESCAPE '!'",
            };
            $query->whereRaw($sql, [$prefix.'%']);
        }
    }
}
