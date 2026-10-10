<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantResourceLimits;
use App\Modules\Tenancy\Domain\Exceptions\TenantLimitExceededException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantTechnicalLimitsTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function limits(): array
    {
        return ['import bytes' => ['import_bytes'], 'import rows' => ['import_rows'], 'export rows' => ['export_rows'],
            'artifact bytes' => ['artifact_bytes'], 'storage bytes' => ['storage_bytes']];
    }

    #[DataProvider('limits')]
    public function test_overrides_and_exhaustion_affect_only_the_named_tenant(string $name): void
    {
        $first = $this->context();
        $second = $this->context();
        config(['tenancy.limit_overrides.'.$first->organizationId => [$name => 2]]);
        $limits = app(TenantResourceLimits::class);
        $limits->assertWithin($first, $name, 2);
        $limits->assertWithin($second, $name, 3);

        $this->expectException(TenantLimitExceededException::class);
        $limits->assertWithin($first, $name, 3);
    }

    public function test_import_and_export_guards_apply_their_distinct_limits(): void
    {
        $context = $this->context();
        config(['tenancy.limit_overrides.'.$context->organizationId => ['import_bytes' => 10, 'import_rows' => 2, 'export_rows' => 3]]);
        $limits = app(TenantResourceLimits::class);
        $limits->assertImport($context, 10, 2);
        $limits->assertExport($context, 3);

        try {
            $limits->assertImport($context, 10, 3);
            $this->fail('Import row limits must be enforced.');
        } catch (TenantLimitExceededException $exception) {
            $this->assertSame('TENANT_LIMIT_EXCEEDED', $exception->errorCode());
        }
        try {
            $limits->assertExport($context, 4);
            $this->fail('Export row limits must be enforced.');
        } catch (TenantLimitExceededException $exception) {
            $this->assertSame('La limite technique de cette organisation est atteinte.', $exception->getMessage());
        }
    }

    /** @return array<string, array{mixed}> */
    public static function invalidConfiguration(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'string' => ['10'], 'boolean' => [true]];
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_operator_limits_fail_closed(mixed $value): void
    {
        $context = $this->context();
        config(['tenancy.limit_overrides.'.$context->organizationId => ['import_rows' => $value]]);

        $this->expectException(\LogicException::class);
        app(TenantResourceLimits::class)->assertImport($context, 0, 0);
    }

    public function test_negative_measurements_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(TenantResourceLimits::class)->assertExport($this->context(), -1);
    }

    private function context(): TenantContext
    {
        return new TenantContext((string) Str::uuid(), 1, 'test', 'UTC', 'fr', (string) Str::uuid());
    }
}
