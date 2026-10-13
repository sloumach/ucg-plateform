<?php

namespace App\Support\Audit;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AuditState
{
    /** @param array<string, mixed> $state
     * @return array<string, bool|int|string|list<string>|null>
     */
    public static function project(array $state, bool $strict = false): array
    {
        $result = [];
        foreach ($state as $key => $value) {
            $valid = match ($key) {
                'team_membership_id','team_movement_id' => is_string($value) && Str::isUuid($value),
                'team_membership_status' => is_string($value) && in_array($value, ['active', 'closed', 'cancelled'], true),
                'team_role_key' => is_string($value) && in_array($value, ['player', 'substitute', 'captain', 'coach', 'analyst'], true),
                'team_movement_action' => is_string($value) && in_array($value, ['created', 'close', 'cancel', 'transfer', 'promotion', 'person_departure'], true),
                'team_movement_reason' => is_string($value) && in_array($value, ['initial', 'sporting_decision', 'promotion', 'transfer', 'end_of_period', 'person_departure', 'other'], true),
                'team_status' => is_string($value) && in_array($value, ['draft', 'active', 'archived'], true),
                'team_changed_fields' => self::listWithin($value, ['game_id', 'slug', 'season', 'name', 'kind', 'custom_category', 'short_name', 'public_description', 'color', 'status']),
                'person_import_id' => is_string($value) && Str::isUuid($value),
                'person_import_status' => is_string($value) && in_array($value, ['uploaded', 'previewed', 'queued', 'processing', 'completed', 'failed'], true),
                'period_changed_fields' => self::listWithin($value, ['kind', 'role_key', 'starts_at', 'ends_at', 'status']),
                'departure_item_changed_fields' => self::listWithin($value, ['kind', 'title', 'notes', 'status', 'due_at']),
                'lifecycle_kind' => is_string($value) && in_array($value, ['assignment', 'unavailability', 'equipment', 'obligation'], true),
                'lifecycle_status' => is_string($value) && in_array($value, ['active', 'closed', 'cancelled', 'open', 'resolved', 'waived'], true),
                'lifecycle_id' => is_string($value) && Str::isUuid($value),
                'departed_at' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $value) === 1,
                'pending_items_count' => is_int($value) && $value >= 0 && $value <= 200,
                'active', 'privileged', 'confirmed', 'account_linked', 'external_id_available' => is_bool($value),
                'game_profile_changed_fields' => self::listWithin($value, ['game_key', 'platform_key', 'region_key', 'rank', 'game_role', 'observed_at', 'status']),
                'external_account_changed_fields' => self::listWithin($value, ['provider_key', 'external_id', 'display_name', 'observed_at', 'status']),
                'person_status' => is_string($value) && in_array($value, ['candidate', 'trial', 'active', 'unavailable', 'substitute', 'former'], true),
                'changed_fields' => self::listWithin($value, ['pseudonym', 'language', 'timezone', 'status', 'biography_public', 'user_id', 'first_name', 'last_name', 'contact_email', 'contact_phone', 'biography_internal']),
                'status' => is_string($value) && in_array($value, ['active', 'suspended', 'revoked', 'expired', 'pending', 'accepted', 'declined', 'closing', 'archived', 'published', 'draft'], true),
                'role' => is_string($value) && in_array($value, ['member', 'administrator', 'superadmin', 'support'], true),
                'version','role_id','scope_id','membership_id','person_id','game_profile_id','external_account_id','organization_game_id','catalogue_game_id','team_id' => is_string($value) && Str::isUuid($value),
                'scope_type' => is_string($value) && in_array($value, ['organization', 'team', 'game', 'cohort', 'event'], true),
                'starts_at','ends_at','expires_at','observed_at' => $value === null || (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/D', $value) === 1),
                'http_status' => is_int($value) && in_array($value, [401, 403, 404], true),
                'roles' => self::listWithin($value, ['member', 'administrator']),
                'permissions' => self::listWithin($value, ['organization.view', 'organization.update', 'memberships.manage', 'invitations.manage', 'roles.manage', 'roles.assign', 'audit.view', 'games.manage', 'teams.manage', 'sports.read', 'sports.write', 'sports.validate', 'sports.publish', 'administrative.read', 'administrative.write', 'administrative.validate', 'financial.read', 'financial.write', 'financial.validate']),
                'actions' => self::listWithin($value, ['organization.inspect', 'access.inspect']),
                default => false,
            };
            if (! $valid) {
                if ($strict) {
                    throw ValidationException::withMessages(['audit' => __('audit.unsafe_state')]);
                }

                continue;
            }
            if (is_array($value)) {
                $result[$key] = array_values(array_filter($value, 'is_string'));
            } elseif (is_string($value) || is_int($value) || is_bool($value) || $value === null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @param list<string> $allowed */
    private static function listWithin(mixed $value, array $allowed): bool
    {
        return is_array($value) && array_is_list($value) && count($value) <= count($allowed)
            && count(array_filter($value, fn (mixed $item): bool => is_string($item) && in_array($item, $allowed, true))) === count($value);
    }
}
