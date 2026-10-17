<?php

namespace Tests\Fixtures;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Tenancy\Domain\Models\Organization;

trait ProvidesMatches
{
    /** @return array{Organization,User,User,Person,Person,GameCatalogueEntry} */
    private function matchScenario(): array
    {
        $this->travelTo('2026-10-18 10:00:00');
        $org = Organization::factory()->create();
        $writer = User::factory()->withMfa()->create();
        $reader = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $writer, ['sports.read', 'sports.write']);
        $this->grantPeopleAccess($org, $reader, ['sports.read']);
        $person = Person::factory()->create(['organization_id' => $org->id, 'user_id' => $reader->id, 'pseudonym' => 'Joueur pilote']);
        $other = Person::factory()->create(['organization_id' => $org->id]);
        $game = GameCatalogueEntry::factory()->create(['default_enabled' => true]);
        $this->actingAsWithMfa($writer);

        return [$org, $writer, $reader, $person, $other, $game];
    }

    private function matchRoot(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id.'/matches';
    }

    /** @return array<string,mixed> */
    private function matchPayload(GameCatalogueEntry $game, Person $person, Person $other): array
    {
        return ['game_id' => $game->id, 'title' => 'Scrim de préparation', 'kind' => 'scrim', 'format' => 'bo3',
            'starts_at' => '2026-10-19T10:00:00+00:00', 'ends_at' => '2026-10-19T12:00:00+00:00', 'origin_timezone' => 'UTC',
            'responsible_person_id' => $person->id, 'participants' => [['kind' => 'person', 'source_id' => $person->id], ['kind' => 'person', 'source_id' => $other->id]]];
    }
}
