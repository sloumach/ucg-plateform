<?php

namespace App\Modules\Tenancy\Presentation\Console;

use App\Exceptions\DomainException;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\ErasureReconciliationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ReconcileOrganizationErasureCommand extends Command
{
    protected $signature = 'organizations:reconcile-erasure {organization} {--actor=} {--reason=} {--request-id=} {--head=} {--confirm=}';

    protected $description = 'Rejoue le journal indépendant après restauration, sous maintenance et sans reprise implicite.';

    public function handle(ErasureReconciliationService $service, Factory $validator): int
    {
        try {
            $values = $validator->make([
                'organization' => $this->argument('organization'), 'actor' => $this->option('actor'), 'reason' => $this->option('reason'),
                'request' => $this->option('request-id') ?? (string) Str::uuid7(), 'head' => $this->option('head'), 'confirm' => $this->option('confirm'),
            ], [
                'organization' => ['required', 'uuid'], 'actor' => ['required', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'], 'request' => ['required', 'uuid'],
                'head' => ['nullable', 'regex:/^[a-f0-9]{64}$/D'], 'confirm' => ['required_with:head', 'nullable', 'uuid', 'same:organization'],
            ])->validate();
            $result = $service->run(strtolower($values['organization']), new OrganizationAuditData((int) $values['actor'],
                $values['reason'], $values['request']), $values['head'], $values['confirm']);
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
