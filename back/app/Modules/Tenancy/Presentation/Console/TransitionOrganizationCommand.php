<?php

namespace App\Modules\Tenancy\Presentation\Console;

use App\Exceptions\DomainException;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\OrganizationService;
use App\Modules\Tenancy\Application\Validation\OrganizationInput;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use Illuminate\Console\Command;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TransitionOrganizationCommand extends Command
{
    protected $signature = 'organizations:transition {organization} {status} {--actor=} {--reason=}';

    protected $description = 'Changer un statut avec motif et audit depuis un accès opérateur au serveur.';

    public function handle(OrganizationService $organizations, Factory $validator): int
    {
        try {
            $input = ['organization' => $this->argument('organization'), 'status' => $this->argument('status'),
                'actor' => $this->option('actor'), 'reason' => $this->option('reason')];
            $validator->make($input, [
                'organization' => ['required', 'uuid'], 'status' => ['required', Rule::enum(OrganizationStatus::class)],
                'actor' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000'],
            ], OrganizationInput::messages())->validate();
            $organization = $organizations->transition((string) $input['organization'],
                OrganizationStatus::from((string) $input['status']),
                new OrganizationAuditData((int) $input['actor'], (string) $input['reason'], (string) Str::uuid()));
            $this->info(__('tenancy.console.transitioned', ['status' => $organization->status->value]));

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error(__('tenancy.console.invalid', ['fields' => implode(', ', array_keys($exception->errors()))]));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());
        } catch (ModelNotFoundException) {
            $this->error(__('api.errors.resource_not_found'));
        }

        return self::FAILURE;
    }
}
