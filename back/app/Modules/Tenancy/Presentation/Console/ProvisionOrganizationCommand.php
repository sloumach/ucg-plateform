<?php

namespace App\Modules\Tenancy\Presentation\Console;

use App\Exceptions\DomainException;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Data\OrganizationDetailsData;
use App\Modules\Tenancy\Application\Services\OrganizationService;
use App\Modules\Tenancy\Application\Validation\OrganizationInput;
use Illuminate\Console\Command;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProvisionOrganizationCommand extends Command
{
    protected $signature = 'organizations:provision {--name=} {--slug=} {--owner=} {--actor=} {--timezone=} {--language=fr} {--country=} {--reason=}';

    protected $description = 'Créer une organisation depuis un accès opérateur au serveur (aucun compte créé).';

    public function handle(OrganizationService $organizations, Factory $validator): int
    {
        try {
            $input = [
                'name' => $this->option('name'), 'slug' => $this->option('slug'),
                'owner' => $this->option('owner'), 'actor' => $this->option('actor'),
                'timezone' => $this->option('timezone'), 'language' => $this->option('language'),
                'country' => $this->option('country'), 'reason' => $this->option('reason'),
                'settings' => ['week_starts_on' => 1, 'date_format' => 'd/m/Y'],
            ];
            $validator->make($input, [
                ...OrganizationInput::detailsRules(), 'slug' => ['required', 'string'],
                'owner' => ['required', 'integer', 'min:1'], 'actor' => ['required', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'max:1000'],
            ], OrganizationInput::messages())->validate();
            $organization = $organizations->create(
                (string) $input['slug'], (int) $input['owner'],
                new OrganizationDetailsData((string) $input['name'], (string) $input['timezone'], (string) $input['language'], (string) $input['country']),
                new OrganizationAuditData((int) $input['actor'], (string) $input['reason'], (string) Str::uuid()),
            );
            $this->info(__('tenancy.console.provisioned', ['id' => $organization->id]));

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error(__('tenancy.console.invalid', ['fields' => implode(', ', array_keys($exception->errors()))]));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
