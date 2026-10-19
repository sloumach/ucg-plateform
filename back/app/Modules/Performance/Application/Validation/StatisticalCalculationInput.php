<?php

namespace App\Modules\Performance\Application\Validation;

use App\Modules\Performance\Domain\Aggregation\StatisticalCalculator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class StatisticalCalculationInput
{
    /** @return array<string,list<string>> */
    public static function rules(string $operation): array
    {
        return match ($operation) {
            'store' => ['metric_id' => ['required', 'uuid'], 'metric_version' => ['required', 'uuid'],
                'observations' => ['required', 'array', 'min:1', 'max:'.StatisticalCalculator::MAX_SOURCES],
                'observations.*' => ['required', 'uuid', 'distinct:ignore_case'], 'confirm' => ['required', 'accepted']],
            'recalculate' => ['version' => ['required', 'uuid'], 'confirm' => ['required', 'accepted']],
            default => ['page' => ['sometimes', 'integer', 'between:1,100000']],
        };
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function validate(Factory $factory, array $input, string $operation): array
    {
        $rules = self::rules($operation);
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['calculation' => __('observations.closed')]);
        }

        return $factory->make($input, $rules)->validate();
    }
}
