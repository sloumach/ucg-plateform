<?php

namespace App\Architecture\Modules;

final class ModuleDependencyMap
{
    /** @return list<ModuleName> */
    public static function dependenciesOf(ModuleName $module): array
    {
        return match ($module) {
            ModuleName::Identity => [],
            ModuleName::Tenancy => [ModuleName::Identity],
            ModuleName::People => [ModuleName::Identity, ModuleName::Tenancy],
            ModuleName::Teams => [ModuleName::Tenancy, ModuleName::People],
            ModuleName::Academy => [ModuleName::Tenancy, ModuleName::People, ModuleName::Teams],
            ModuleName::Scheduling => [ModuleName::Tenancy, ModuleName::People, ModuleName::Teams],
            ModuleName::Competition => [ModuleName::Tenancy, ModuleName::Teams, ModuleName::Scheduling],
            ModuleName::Performance => [
                ModuleName::Tenancy,
                ModuleName::People,
                ModuleName::Teams,
                ModuleName::Competition,
            ],
            ModuleName::Contracts => [ModuleName::Tenancy, ModuleName::People],
            ModuleName::Partnerships => [ModuleName::Tenancy],
            ModuleName::Finance => [
                ModuleName::Tenancy,
                ModuleName::Contracts,
                ModuleName::Partnerships,
            ],
            ModuleName::Media => [ModuleName::Tenancy, ModuleName::People, ModuleName::Teams],
            ModuleName::Reporting => [
                ModuleName::Tenancy,
                ModuleName::People,
                ModuleName::Teams,
                ModuleName::Academy,
                ModuleName::Scheduling,
                ModuleName::Competition,
                ModuleName::Performance,
                ModuleName::Contracts,
                ModuleName::Partnerships,
                ModuleName::Finance,
                ModuleName::Media,
            ],
        };
    }

    public static function allows(ModuleName $source, ModuleName $target): bool
    {
        return $source === $target
            || in_array($target, self::dependenciesOf($source), true);
    }
}
