<?php

namespace App\Modules\Tenancy\Presentation\Console;

use App\Exceptions\DomainException;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\RetentionControlService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ControlOrganizationRetentionCommand extends Command
{
    protected $signature = 'organizations:retention-control {organization} {--action=inspect} {--actor=} {--reason=}
        {--expected-version=} {--reference=} {--confirm=} {--request-id=}';

    protected $description = 'Contrôles de conservation par organisation ; aucun effacement métier.';

    public function handle(RetentionControlService $service, Factory $validator): int
    {
        try {
            $action = $this->option('action');
            $values = $validator->make([
                'organization' => $this->argument('organization'), 'action' => $action,
                'actor' => $this->option('actor'), 'reason' => $this->option('reason'),
                'version' => $this->option('expected-version'), 'reference' => $this->option('reference'),
                'confirm' => $this->option('confirm'), 'request_id' => $this->option('request-id') ?? (string) Str::uuid7(),
            ], [
                'organization' => ['required', 'uuid'], 'action' => ['required', Rule::in(RetentionControlService::ACTIONS)],
                'actor' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
                'version' => [$action === 'inspect' ? 'nullable' : 'required', 'integer', 'min:0', 'max:2147483646'],
                'reference' => [$action === 'inspect' ? 'nullable' : 'required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D'],
                'confirm' => [$action === 'inspect' ? 'nullable' : 'required', 'uuid', 'same:organization'],
                'request_id' => ['required', 'uuid'],
            ])->validate();
            $report = $service->run(strtolower($values['organization']), new OrganizationAuditData((int) $values['actor'],
                $values['reason'], $values['request_id']), $values['action'], $values['version'] === null ? null : (int) $values['version'],
                $values['reference'], $values['confirm']);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error(__('tenancy.console.invalid', ['fields' => implode(', ', array_keys($exception->errors()))]));
        } catch (AuthorizationException) {
            $this->error(__('retention.control_forbidden'));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage().' ['.$exception->errorCode().']');
        } catch (ModelNotFoundException) {
            $this->error(__('api.errors.resource_not_found'));
        } catch (Throwable $exception) {
            report($exception);
            $this->error(__('retention.control_failed'));
        }

        return self::FAILURE;
    }
}
