<?php

namespace App\Modules\Teams\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Teams\Application\Validation\TeamEligibilityInput as Input;
use App\Modules\Teams\Domain\Contracts\TeamEligibilityRepository;
use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class TeamEligibilityRulesService
{
    public function __construct(private TeamEligibilityRepository $records, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private EffectiveGameCatalogue $games, private TeamEligibilityJournal $journal, private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,TeamEligibilityRuleset>
     */
    public function list(TenantContext $context, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = Input::validate($this->validator, $filters, Input::readRules());
        if (isset($values['game_id'])) {
            $values['game_id'] = strtolower($values['game_id']);
        }

        return $this->records->rules($context->organizationId, $values);
    }

    /** @param array<string,mixed> $input */
    public function create(TenantContext $context, array $input): TeamEligibilityRuleset
    {
        $values = Input::validate($this->validator, $input, Input::createRules());
        $this->authorize($context);
        if ((int) $values['max_starters'] + (int) $values['max_substitutes'] > 50) {
            throw ValidationException::withMessages(['max_starters' => __('team_eligibility.range')]);
        }
        if (isset($values['min_age'],$values['max_age']) && (int) $values['min_age'] > (int) $values['max_age']) {
            throw ValidationException::withMessages(['max_age' => __('team_eligibility.range')]);
        }
        $values['lock_at'] = $values['lock_at'] === null ? null : CarbonImmutable::parse($values['lock_at'])->utc();
        if ($values['platform_key'] === null && ($values['allowed_regions'] !== [] || $values['allowed_ranks'] !== [])) {
            throw ValidationException::withMessages(['platform_key' => __('team_eligibility.platform')]);
        }

        return $this->database->transaction(function () use ($context, $values): TeamEligibilityRuleset {
            $this->guard->lock($context);
            $this->authorize($context);
            if ($this->records->countRules($context->organizationId) >= Input::MAX_RULESETS) {
                throw new DomainConflictException(__('team_eligibility.limit'), 'TEAM_ELIGIBILITY_LIMIT');
            }
            $gameId = strtolower($values['game_id']);
            $this->games->assertGameAvailable($context->organizationId, $gameId);
            if ($values['platform_key'] !== null) {
                $this->games->assertReference($context->organizationId, $gameId, 'platform', $values['platform_key']);
            }
            foreach ($values['allowed_regions'] as $region) {
                $this->games->assertReference($context->organizationId, $gameId, 'region', $region);
            }
            $rule = $this->records->saveRule(null, [...$values, 'game_id' => $gameId, 'organization_id' => $context->organizationId,
                'version' => (string) Str::uuid(), 'status' => 'active', 'created_by' => $context->actorUserId]);
            $this->journal->record($context, $rule->id, 'created', 'active');

            return $rule;
        });
    }

    /** @param array<string,mixed> $input */
    public function archive(TenantContext $context, string $id, array $input): TeamEligibilityRuleset
    {
        $values = Input::validate($this->validator, $input, Input::archiveRules());
        $this->authorize($context);

        return $this->database->transaction(function () use ($context, $id, $values): TeamEligibilityRuleset {
            $this->guard->lock($context);
            $this->authorize($context);
            $rule = $this->records->findRule($context->organizationId, $id, true);
            if ($rule->version !== strtolower($values['version'])) {
                throw new DomainConflictException(__('team_eligibility.stale'), 'TEAM_RULESET_VERSION_STALE');
            }
            if ($rule->status !== 'active') {
                throw new DomainConflictException(__('team_eligibility.immutable'), 'TEAM_RULESET_IMMUTABLE');
            }
            $rule = $this->records->saveRule($rule, ['status' => 'archived']);
            $this->journal->record($context, $rule->id, 'archived', 'archived');

            return $rule;
        });
    }

    private function authorize(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'teams.manage');
    }
}
