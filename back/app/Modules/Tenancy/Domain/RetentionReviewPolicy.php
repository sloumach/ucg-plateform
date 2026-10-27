<?php

namespace App\Modules\Tenancy\Domain;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class RetentionReviewPolicy
{
    private const int MAX_DAYS = 36500;

    /** @param array<string, array{event: string, active_days: int|null, archive_days: int|null, final_action: string}> $rules */
    private function __construct(public string $status, public ?string $version, private array $rules, public ?string $fingerprint = null) {}

    public static function fromConfiguration(mixed $value, CarbonImmutable $asOf): self
    {
        if ($value === null) {
            return new self('missing', null, []);
        }
        if (! self::keys($value, ['version', 'reviewed_on', 'responsible_reference', 'validation_reference', 'reviewed', 'rules'])
            || $value['reviewed'] !== true || ! self::reference($value['version'])
            || ! self::reference($value['responsible_reference']) || ! self::reference($value['validation_reference'])
            || ($reviewed = self::date($value['reviewed_on'])) === null || $reviewed->greaterThan($asOf->utc()->startOfDay())
            || ! is_array($value['rules']) || $value['rules'] === [] || count($value['rules']) > count(RetentionCatalog::FAMILIES)) {
            return new self('invalid', null, []);
        }
        $rules = [];
        foreach ($value['rules'] as $family => $rule) {
            if (! is_string($family) || ! array_key_exists($family, RetentionCatalog::FAMILIES)
                || ! self::keys($rule, ['event', 'active_days', 'archive_days', 'final_action'])
                || ! in_array($rule['event'], $family === 'people' ? ['manual_review', 'recorded_departure'] : ['manual_review'], true)) {
                return new self('invalid', null, []);
            }
            $indefinite = $rule['event'] === 'manual_review' && $rule['active_days'] === null
                && $rule['archive_days'] === null && $rule['final_action'] === 'preserve';
            if (! $indefinite && (! self::days($rule['active_days']) || ($rule['archive_days'] !== null && ! self::days($rule['archive_days']))
                || $rule['final_action'] !== 'review')) {
                return new self('invalid', null, []);
            }
            $rules[$family] = $rule;
        }

        return new self('configured_review_only', $value['version'], $rules, hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)));
    }

    /** @return array{event: string, active_days: int|null, archive_days: int|null, final_action: string}|null */
    public function rule(string $family): ?array
    {
        return $this->rules[$family] ?? null;
    }

    public static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (InvalidArgumentException) {
            return null;
        }

        return $date !== null && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** @param list<string> $keys */
    private static function keys(mixed $value, array $keys): bool
    {
        return is_array($value) && count($value) === count($keys) && array_diff($keys, array_keys($value)) === [];
    }

    private static function reference(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D', $value) === 1;
    }

    private static function days(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= self::MAX_DAYS;
    }
}
