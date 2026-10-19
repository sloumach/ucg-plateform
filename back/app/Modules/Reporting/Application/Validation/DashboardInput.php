<?php

namespace App\Modules\Reporting\Application\Validation;

use App\Modules\Reporting\Domain\DashboardCatalogue;
use App\Support\Data\DashboardWindow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DashboardInput
{
    /** @return array<string,mixed> */
    public static function rules(string $action): array
    {
        return $action === 'index' ? [] : [
            'view' => ['required', Rule::in(DashboardCatalogue::VIEWS)],
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'between:1,10000'],
        ];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function validate(Factory $validator, array $input, string $action): array
    {
        if (array_diff(array_keys($input), array_keys(self::rules($action))) !== []) {
            throw ValidationException::withMessages(['dashboard' => __('dashboards.closed')]);
        }
        $values = $validator->make($input, self::rules($action))->validate();
        if ($action !== 'index' && CarbonImmutable::parse($values['from'], 'UTC')->diffInDays(CarbonImmutable::parse($values['to'], 'UTC')) >= DashboardWindow::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('dashboards.period')]);
        }

        return $values;
    }
}
