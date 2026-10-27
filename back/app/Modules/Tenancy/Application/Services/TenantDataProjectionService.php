<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Modules\Tenancy\Application\Contracts\AuthorizationScopeDirectory;
use App\Modules\Tenancy\Application\Contracts\FieldVisibility;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantDataRecord;
use App\Modules\Tenancy\Application\Contracts\TenantDataVisibility;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

final readonly class TenantDataProjectionService implements TenantDataVisibility
{
    private const int MAX_RECORDS = 200;

    public function __construct(private TenantAuthorization $authorization, private AuthorizationScopeDirectory $scopes) {}

    public function project(TenantContext $context, TenantDataRecord $record, array $fields): array
    {
        return $this->projectMany($context, [$record], $fields)[0];
    }

    public function projectMany(TenantContext $context, array $records, array $fields): array
    {
        if (count($records) > self::MAX_RECORDS) {
            throw new InvalidArgumentException('Project a paginated result or a chunk of at most 200 records.');
        }
        $this->validateFields($fields);
        $verifiedScopes = [];
        foreach ($records as $record) {
            $context->assertOrganization($record->organizationId);
            $scopeId = $record->scopeId ?? $context->organizationId;
            $scopeKey = json_encode([$record->scopeType, $scopeId], JSON_THROW_ON_ERROR);
            $verifiedScopes[$scopeKey] ??= $this->scopes->exists($context->organizationId, $record->scopeType, $scopeId);
            if (! $verifiedScopes[$scopeKey]) {
                throw new AuthorizationException;
            }
        }
        $decisions = [];
        $organizationScope = json_encode(['organization', $context->organizationId], JSON_THROW_ON_ERROR);
        if ($records !== [] && count($verifiedScopes) === 1 && isset($verifiedScopes[$organizationScope])
            && count(array_unique(array_column($fields, 'permission'))) > 1) {
            // Display-only decisions share a fresh snapshot within this projection,
            // never across calls; scoped records keep their individual checks.
            try {
                $permissions = array_fill_keys($this->authorization->permissions($context), true);
            } catch (ModelNotFoundException) {
                throw new AuthorizationException;
            }
            if (! isset($permissions['organization.view'])) {
                throw new AuthorizationException;
            }
            foreach (Permission::cases() as $permission) {
                $key = json_encode([$permission->value, 'organization', $context->organizationId], JSON_THROW_ON_ERROR);
                $decisions[$key] = isset($permissions[$permission->value]);
            }
        } else {
            $this->authorization->authorize($context, 'organization.view');
            $key = json_encode(['organization.view', 'organization', $context->organizationId], JSON_THROW_ON_ERROR);
            $decisions[$key] = true;
        }
        $result = [];
        foreach ($records as $record) {
            $result[] = $this->filter($context, $record, $record->data, $fields, $decisions);
        }

        return $result;
    }

    /** @param array<string, FieldVisibility> $fields */
    private function validateFields(array $fields): void
    {
        foreach ($fields as $name => $field) {
            if ($name === '' || Permission::tryFrom($field->permission) === null || ($field->collection && $field->children === [])) {
                throw new InvalidArgumentException('Output fields require a known permission and an explicit nested schema.');
            }
            $this->validateFields($field->children);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, FieldVisibility>  $fields
     * @param  array<string, bool>  $decisions
     * @return array<string, mixed>
     */
    private function filter(TenantContext $context, TenantDataRecord $record, array $data, array $fields, array &$decisions): array
    {
        $result = [];
        foreach ($fields as $name => $field) {
            $organizationOnly = Permission::from($field->permission)->organizationOnly();
            $type = $organizationOnly ? 'organization' : $record->scopeType;
            $id = $organizationOnly ? $context->organizationId : ($record->scopeId ?? $context->organizationId);
            $key = json_encode([$field->permission, $type, $id], JSON_THROW_ON_ERROR);
            $decisions[$key] ??= $this->authorization->allows($context, $field->permission, $type, $id);
            if (! $decisions[$key] || ! array_key_exists($name, $data)) {
                continue;
            }
            $value = $data[$name];
            if ($value === null) {
                $result[$name] = null;
            } elseif ($field->children === []) {
                if ($this->isLeaf($value)) {
                    $result[$name] = $value;
                } else {
                    throw new InvalidArgumentException('Structured output requires an explicit nested schema.');
                }
            } elseif (! is_array($value) || ($value !== [] && array_is_list($value) !== $field->collection)) {
                throw new InvalidArgumentException('Output does not match its declared nested schema.');
            } elseif ($field->collection) {
                $items = [];
                foreach ($value as $item) {
                    if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                        throw new InvalidArgumentException('Output collections require objects with an explicit schema.');
                    }
                    $items[] = $this->filter($context, $record, $item, $field->children, $decisions);
                }
                $result[$name] = $items;
            } else {
                $result[$name] = $this->filter($context, $record, $value, $field->children, $decisions);
            }
        }

        return $result;
    }

    private function isLeaf(mixed $value): bool
    {
        if (is_scalar($value)) {
            return true;
        }
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if ($item !== null && ! is_scalar($item)) {
                return false;
            }
        }

        return true;
    }
}
