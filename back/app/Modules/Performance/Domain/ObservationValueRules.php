<?php

namespace App\Modules\Performance\Domain;

use DomainException;

final class ObservationValueRules
{
    public const array FIELDS = ['availability', 'value', 'missing_reason', 'notes', 'status'];

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function normalize(array $input): array
    {
        if (mb_strlen(trim($input['reason'])) < 10) {
            throw new DomainException('reason');
        }
        if (($input['availability'] === 'available' && ($input['value'] === null || $input['missing_reason'] !== null))
            || ($input['availability'] === 'unavailable' && ($input['value'] !== null || $input['missing_reason'] === null))) {
            throw new DomainException('value');
        }
        $values = array_intersect_key($input, array_flip(self::FIELDS));
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = trim($value);
            }
        }
        // Decimal strings preserve precision and keep measured zero distinct from missing data.
        if ($values['value'] !== null) {
            $values['value'] = str_contains($values['value'], '.') ? rtrim(rtrim($values['value'], '0'), '.') : $values['value'];
            if ($values['value'] === '-0') {
                $values['value'] = '0';
            }
        }

        return $values;
    }
}
