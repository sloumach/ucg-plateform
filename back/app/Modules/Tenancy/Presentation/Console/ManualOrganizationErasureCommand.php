<?php

namespace App\Modules\Tenancy\Presentation\Console;

use App\Exceptions\DomainException;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\ManualErasureService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ManualOrganizationErasureCommand extends Command
{
    protected $signature = 'organizations:erase {organization} {--action=plan} {--scope=person} {--target=}
        {--actor=} {--reason=} {--request-id=} {--digest=} {--reference=} {--confirm=} {--operation=} {--category=}';

    protected $description = 'Effacement manuel confirmé, borné et audité ; désactivé par défaut.';

    public function handle(ManualErasureService $service, Factory $validator): int
    {
        try {
            $values = $validator->make([
                'organization' => $this->argument('organization'), 'action' => $this->option('action'),
                'scope' => $this->option('scope'), 'target' => $this->option('target'),
                'actor' => $this->option('actor'), 'reason' => $this->option('reason'),
                'request' => $this->option('request-id') ?? (string) Str::uuid7(),
                'digest' => $this->option('digest'), 'reference' => $this->option('reference'),
                'confirm' => $this->option('confirm'), 'operation' => $this->option('operation'), 'category' => $this->option('category'),
            ], [
                'organization' => ['required', 'uuid'], 'action' => ['required', Rule::in(['plan', 'initialize-journal', 'begin', 'inspect', 'resume', 'acknowledge'])],
                'actor' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
                'request' => ['required', 'uuid'], 'scope' => ['required', Rule::in(['person', 'organization'])],
                'target' => ['required_if:action,plan,begin', 'nullable', 'uuid'],
                'digest' => ['required_if:action,begin', 'nullable', 'regex:/^[a-f0-9]{64}$/D'],
                'reference' => ['required_if:action,begin,acknowledge', 'nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D'],
                'confirm' => ['required_if:action,begin', 'nullable', 'uuid', 'same:target'],
                'operation' => ['required_if:action,inspect,resume,acknowledge', 'nullable', 'uuid'],
                'category' => ['required_if:action,acknowledge', 'nullable', 'string'],
            ])->validate();
            $org = strtolower($values['organization']);
            $audit = new OrganizationAuditData((int) $values['actor'], $values['reason'], $values['request']);
            $result = match ($values['action']) {
                'plan' => $service->plan($org, $audit, $values['scope'], strtolower($values['target'])),
                'initialize-journal' => $service->initializeJournal($org, $audit),
                'begin' => $service->begin($org, $audit, $values['scope'], strtolower($values['target']), $values['digest'], $values['reference'], strtolower($values['confirm'])),
                'inspect' => $service->inspect($org, $audit, $values['operation']),
                'resume' => $service->resume($org, $audit, $values['operation']),
                'acknowledge' => $service->acknowledge($org, $audit, $values['operation'], $values['category'], $values['reference']),
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error(__('tenancy.console.invalid', ['fields' => implode(', ', array_keys($exception->errors()))]));
        } catch (AuthorizationException) {
            $this->error(__('retention.control_forbidden'));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage().' ['.$exception->errorCode().']');
        } catch (Throwable $exception) {
            report($exception);
            $this->error(__('retention.control_failed'));
        }

        return self::FAILURE;
    }
}
