<?php

namespace App\Modules\Tenancy\Presentation\Console;

use App\Exceptions\DomainException;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\OrganizationPortabilityService;
use App\Modules\Tenancy\Application\Services\OrganizationPurgeService;
use App\Modules\Tenancy\Application\Services\OrganizationRecoveryService;
use App\Modules\Tenancy\Application\Validation\OrganizationInput;
use Illuminate\Console\Command;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class OrganizationPortabilityCommand extends Command
{
    protected $signature = 'organizations:portability {organization} {action : export, inspect, read, expire, purge ou recover}
        {--actor=} {--reason=} {--operation=} {--source-export=} {--confirm=} {--execute}';

    protected $description = 'Opérations tenant sensibles réservées à un accès opérateur au serveur.';

    public function handle(OrganizationPortabilityService $portability, OrganizationPurgeService $purge,
        OrganizationRecoveryService $recovery, Factory $validator): int
    {
        try {
            $input = ['organization' => $this->argument('organization'), 'action' => $this->argument('action'),
                'actor' => $this->option('actor'), 'reason' => $this->option('reason'), 'operation' => $this->option('operation'),
                'source' => $this->option('source-export'), 'confirmation' => $this->option('confirm')];
            $validator->make($input, [
                'organization' => ['required', 'uuid'], 'actor' => ['required', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
                'action' => ['required', Rule::in(['export', 'inspect', 'read', 'expire', 'purge', 'recover'])],
                'operation' => ['nullable', 'uuid', 'required_if:action,inspect,read,expire'],
                'source' => ['nullable', 'uuid', 'required_if:action,recover'],
                'confirmation' => ['nullable', 'uuid', 'required_if:action,recover'],
            ], OrganizationInput::messages())->validate();
            $id = strtolower((string) $input['organization']);
            $operationId = $input['operation'] === null ? null : strtolower((string) $input['operation']);
            $audit = new OrganizationAuditData((int) $input['actor'], (string) $input['reason'], (string) Str::uuid7());
            switch ($input['action']) {
                case 'export':
                    if ($operationId !== null) {
                        $portability->retryExport($id, $operationId, $audit);
                        $result = $portability->inspect($id, $operationId, $audit);
                    } else {
                        $result = $portability->requestExport($id, $audit);
                    }
                    break;
                case 'inspect':
                    $result = $portability->inspect($id, (string) $operationId, $audit);
                    break;
                case 'read':
                    $stream = $portability->read($id, (string) $operationId, $audit);
                    try {
                        while (! feof($stream)) {
                            $chunk = fread($stream, 1048576);
                            if ($chunk === false) {
                                throw new \RuntimeException('Export stream interrupted.');
                            }
                            $this->output->write($chunk, false, OutputInterface::OUTPUT_RAW);
                        }
                    } finally {
                        fclose($stream);
                    }

                    return self::SUCCESS;
                case 'expire':
                    $portability->inspect($id, (string) $operationId, $audit);
                    $portability->expire($id, (string) $operationId);
                    $result = $portability->inspect($id, (string) $operationId, $audit);
                    break;
                case 'purge':
                    $result = $this->option('execute')
                        ? $purge->execute($id, strtolower((string) $input['confirmation']),
                            strtolower((string) $input['source']), $audit, $operationId)
                        : $purge->plan($id, $audit);
                    break;
                default:
                    $result = $recovery->recover($id, strtolower((string) $input['confirmation']), strtolower((string) $input['source']), $audit);
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error(__('tenancy.console.invalid', ['fields' => implode(', ', array_keys($exception->errors()))]));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage().' ['.$exception->errorCode().']');
        } catch (ModelNotFoundException) {
            $this->error(__('api.errors.resource_not_found'));
        } catch (Throwable $exception) {
            report($exception);
            $this->error(__('tenancy.portability.failed'));
        }

        return self::FAILURE;
    }
}
