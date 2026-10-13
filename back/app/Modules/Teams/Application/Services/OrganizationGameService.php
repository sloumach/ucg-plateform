<?php

namespace App\Modules\Teams\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Teams\Application\Validation\GameCatalogueInput;
use App\Modules\Teams\Domain\Contracts\GameCatalogueRepository;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class OrganizationGameService
{
    public function __construct(private GameCatalogueRepository $repository, private GameCatalogueService $global,
        private EffectiveGameCatalogue $effective, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TenantAuditJournal $audit, private ConnectionInterface $database, private Factory $validator) {}

    /** @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int, per_page: int, page: int, catalogue_version: string}
     */
    public function entries(TenantContext $context, array $filters): array
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = GameCatalogueInput::validate($this->validator, $filters, GameCatalogueInput::pageRules(false));
        $revision = $this->global->version();
        $page = $this->repository->paginate('game', null, (int) ($values['per_page'] ?? 10), (int) ($values['page'] ?? 1));
        $settings = [];
        foreach ($this->repository->settings($context->organizationId) as $setting) {
            $settings[$setting->game_id] = $setting;
        }
        $items = [];
        foreach ($page->items() as $game) {
            $items[] = $this->project($game, $settings[$game->id] ?? null);
        }

        return ['items' => $items, 'total' => $page->total(), 'per_page' => $page->perPage(), 'page' => $page->currentPage(), 'catalogue_version' => $revision];
    }

    /** @return array<string, mixed> */
    public function find(TenantContext $context, string $gameId): array
    {
        $this->authorization->authorize($context, 'sports.read');
        $revision = $this->global->version();
        $game = $this->global->game($gameId);
        $setting = $this->repository->setting($context->organizationId, $game->id);
        $options = $this->effective->options($game);

        $enabled = $game->active && ($setting->enabled ?? $game->default_enabled);

        return [...$this->project($game, $setting), 'options' => $options,
            'effective_options' => $enabled ? $this->effective->restrict($options, $setting) : array_fill_keys(GameCatalogueInput::RESTRICTIONS, []),
            'catalogue_version' => $revision];
    }

    /** @param array<string, mixed> $input */
    public function save(TenantContext $context, string $gameId, array $input, ?string $ipAddress = null): OrganizationGame
    {
        $values = GameCatalogueInput::validate($this->validator, $input, GameCatalogueInput::settingRules());
        $this->authorize($context);

        return $this->database->transaction(function () use ($context, $gameId, $values, $ipAddress): OrganizationGame {
            $this->guard->lock($context);
            $this->authorize($context);
            $this->global->assertVersion((string) $values['catalogue_version'], true);
            $game = $this->global->game($gameId);
            $setting = $this->repository->setting($context->organizationId, $game->id, true);
            if ($setting?->version !== $values['version']) {
                throw new DomainConflictException(__('game_catalogue.stale'), 'ORGANIZATION_GAME_VERSION_STALE');
            }
            if ($values['enabled'] && ! $game->active) {
                throw new DomainConflictException(__('game_profiles.context_unavailable'), 'GAME_CONTEXT_UNAVAILABLE');
            }
            $options = $this->effective->options($game);
            /** @var array<string, list<string>> $restrictions */
            $restrictions = $values['restrictions'];
            foreach ($restrictions as $kind => $keys) {
                // Existing retired choices may be retained or removed; they never become effective again locally.
                $allowed = [...array_column($options[$kind], 'key'), ...($setting->restrictions[$kind] ?? [])];
                if (array_diff($keys, $allowed) !== []) {
                    throw ValidationException::withMessages(['restrictions.'.$kind => __('game_catalogue.reference_invalid')]);
                }
            }
            $before = $setting === null ? [] : $this->state($setting);
            $saved = $this->repository->saveSetting($setting, ['organization_id' => $context->organizationId, 'game_id' => $game->id,
                'enabled' => (bool) $values['enabled'], 'restrictions' => $restrictions, 'version' => (string) Str::uuid()]);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId,
                AuditAction::DomainChanged, AuditTarget::Organization, $context->organizationId, AuditReason::Domain,
                $context->requestId, $ipAddress, $before, $this->state($saved)));

            return $saved;
        });
    }

    private function authorize(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'games.manage');
    }

    /** @return array<string, mixed> */
    private function project(GameCatalogueEntry $game, ?OrganizationGame $setting): array
    {
        return ['id' => $game->id, 'key' => $game->key, 'name' => $game->name, 'available' => $game->active,
            'enabled' => $game->active && ($setting->enabled ?? $game->default_enabled),
            'configured_enabled' => $setting->enabled ?? $game->default_enabled,
            'restrictions' => $setting->restrictions ?? [], 'version' => $setting?->version];
    }

    /** @return array<string, mixed> */
    private function state(OrganizationGame $setting): array
    {
        return ['organization_game_id' => $setting->id, 'catalogue_game_id' => $setting->game_id,
            'active' => $setting->enabled, 'version' => $setting->version];
    }
}
