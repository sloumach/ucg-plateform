<?php

namespace App\Modules\Tenancy\Presentation\Console;

use App\Exceptions\DomainException;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Data\RetentionReviewQuery;
use App\Modules\Tenancy\Application\Services\RetentionReviewService;
use App\Modules\Tenancy\Application\Validation\OrganizationInput;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ReviewOrganizationRetentionCommand extends Command
{
    protected $signature = 'organizations:retention-review {organization} {--actor=} {--reason=}
        {--section=inventory} {--as-of=} {--page=1} {--per-page=25}';

    protected $description = 'Aperçu de conservation paginé, réservé aux opérateurs serveur autorisés ; aucune suppression.';

    public function handle(RetentionReviewService $service, Factory $validator): int
    {
        try {
            $input = ['organization' => $this->argument('organization'), 'actor' => $this->option('actor'),
                'reason' => $this->option('reason'), 'section' => $this->option('section'),
                'as_of' => $this->option('as-of') ?? CarbonImmutable::now('UTC')->toDateString(),
                'page' => $this->option('page'), 'per_page' => $this->option('per-page')];
            $values = $validator->make($input, [
                'organization' => ['required', 'uuid'], 'actor' => ['required', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
                'section' => ['required', Rule::in(['inventory', 'people'])], 'as_of' => ['required', 'date_format:Y-m-d'],
                'page' => ['required', 'integer', 'min:1', 'max:10000'], 'per_page' => ['required', 'integer', 'min:1', 'max:100'],
            ], OrganizationInput::messages())->validate();
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $values['as_of'], 'UTC');
            if ($date === null) {
                throw ValidationException::withMessages(['as_of' => __('retention.invalid_date')]);
            }
            $report = $service->review(strtolower($values['organization']),
                new OrganizationAuditData((int) $values['actor'], $values['reason'], (string) Str::uuid7()),
                new RetentionReviewQuery($values['section'], $date, (int) $values['page'], (int) $values['per_page']));
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error(__('tenancy.console.invalid', ['fields' => implode(', ', array_keys($exception->errors()))]));
        } catch (AuthorizationException) {
            $this->error(__('retention.forbidden'));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage().' ['.$exception->errorCode().']');
        } catch (ModelNotFoundException) {
            $this->error(__('api.errors.resource_not_found'));
        } catch (Throwable $exception) {
            report($exception);
            $this->error(__('retention.failed'));
        }

        return self::FAILURE;
    }
}
