<?php

namespace App\Modules\Tenancy\Domain;

enum OrganizationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closing = 'closing';
    case Archived = 'archived';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Active => in_array($target, [self::Suspended, self::Closing], true),
            self::Suspended => in_array($target, [self::Active, self::Closing], true),
            self::Closing => $target === self::Archived,
            self::Archived => false,
        };
    }
}
