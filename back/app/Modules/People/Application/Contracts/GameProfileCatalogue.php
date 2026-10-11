<?php

namespace App\Modules\People\Application\Contracts;

/** Teams can implement this port without introducing a People -> Teams dependency. */
interface GameProfileCatalogue
{
    /** @return list<array{key: string, name: string, regions: list<array{key: string, name: string}>, platforms: list<array{key: string, name: string, providers: list<array{key: string, name: string}>}>}> */
    public function entries(string $organizationId): array;

    public function assertAvailable(string $organizationId, string $gameKey, string $platformKey, string $regionKey, ?string $providerKey = null): void;
}
