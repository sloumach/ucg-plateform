<?php

namespace Tests\Feature;

use App\Modules\Scheduling\Application\Validation\AvailabilityInput;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AvailabilityInputTest extends TestCase
{
    /** @return array<string,array{string,array<string,mixed>,string}> */
    public static function invalid(): array
    {
        return [
            'unknown status' => ['store', ['status' => 'sick'], 'status'], 'null status' => ['store', ['status' => null], 'status'],
            'array comment' => ['store', ['comment' => ['hidden']], 'comment'], 'large comment' => ['store', ['comment' => str_repeat('é', 1001)], 'comment'],
            'bad timezone' => ['store', ['origin_timezone' => 'unknown'], 'origin_timezone'], 'bad uuid' => ['store', ['person_id' => '1'], 'person_id'],
            'date seconds required' => ['store', ['starts_at' => '2026-10-18T10:00+02:00'], 'starts_at'],
            'forged tenant' => ['store', ['organization_id' => 'forged'], 'organization_id'],
            'medical field' => ['store', ['medical_reason' => 'none'], 'medical_reason'],
            'literal dotted key' => ['store', ['comment.*' => 'none'], 'comment.*'],
            'history injection' => ['history', ['sort' => 'comment'], 'sort'],
            'page too high' => ['history', ['page' => 100001], 'page'],
            'page size too high' => ['history', ['per_page' => 101], 'per_page'],
            'page zero' => ['history', ['page' => 0], 'page'],
            'wrong window date' => ['index', ['from_date' => '2026-02-30'], 'from_date'],
            'boolean shape' => ['index', ['collective' => 'true'], 'collective'],
            'cancelled shape' => ['index', ['cancelled' => []], 'cancelled'],
            'immutable person' => ['update', ['person_id' => '11111111-1111-4111-8111-111111111111'], 'person_id'],
            'missing version' => ['update', [], 'version'],
            'refused cancel' => ['update', ['cancel' => false], 'cancel'],
        ];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalid')]
    public function test_invalid_input_returns_localized_validation_errors(string $operation, array $changes, string $field): void
    {
        $base = match ($operation) {
            'store' => ['status' => 'available', 'starts_at' => '2026-10-18T10:00:00+02:00', 'ends_at' => '2026-10-18T11:00:00+02:00', 'origin_timezone' => 'Europe/Paris'],
            'index' => ['from_date' => '2026-10-18', 'to_date' => '2026-10-19'],
            default => [],
        };
        try {
            AvailabilityInput::validate(app(Factory::class), [...$base, ...$changes], $operation);
            $this->fail('Invalid input accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
            $this->assertNotEmpty($error->errors()[$field][0]);
            $this->assertStringNotContainsString('validation.', $error->errors()[$field][0]);
        }
    }

    public function test_optional_comment_and_three_statuses_are_accepted(): void
    {
        foreach (['available', 'unavailable', 'uncertain'] as $status) {
            $result = AvailabilityInput::validate(app(Factory::class), ['status' => $status, 'comment' => null, 'starts_at' => '2026-10-18T10:00:00+02:00',
                'ends_at' => '2026-10-18T11:00:00+02:00', 'origin_timezone' => 'Europe/Paris'], 'store');
            $this->assertSame($status, $result['status']);
            $this->assertNull($result['comment']);
        }
    }
}
