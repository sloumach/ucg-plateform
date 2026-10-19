<?php

namespace Tests\Fixtures;

use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Support\Str;

trait ProvidesCompetitionRegistrations
{
    /**
     * @return array<string,mixed> */
    private function registrationScenario(bool $save = false): array
    {
        $s = $this->competitionScenario();
        foreach ([[$s['writer'], true], [$s['reader'], false]] as [$user,$write]) {
            $assignment = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $user->id)->firstOrFail();
            $permissions = ['sports.read', 'sports.competitions.read', 'sports.registrations.read', ...($write ? ['sports.registrations.write', 'sports.competitions.write'] : [])];
            OrganizationRole::query()->findOrFail($assignment->role_id)->forceFill(['permissions' => $permissions])->save();
        }
        $player = Person::factory()->create(['organization_id' => $s['org']->id, 'pseudonym' => 'Joueur <script>privé</script>', 'status' => 'active']);
        $manager = Person::factory()->create(['organization_id' => $s['org']->id, 'user_id' => $s['writer']->id, 'pseudonym' => 'Manager privé', 'status' => 'active']);
        $s['player'] = $player;
        $s['manager'] = $manager;
        $s['path'] = $s['root'].'/'.$s['record']['id'].'/registration';
        $s['input'] = ['competition_version' => $s['record']['version'], 'expected_version' => null, 'registration_status' => 'preparing', 'payment_status' => 'pending', 'roster_status' => 'draft', 'checkin_status' => 'not_open',
            'external_reference' => null, 'source_url' => null, 'notes' => 'Suivi interne privé', 'requirements' => ['age' => 'Exigence déclarée par la source, sans calcul automatique.', 'residence' => null, 'rank' => null],
            'roster' => [['person_id' => $player->id, 'role' => 'starter']],
            'items' => [['id' => (string) Str::uuid(), 'kind' => 'document', 'title' => 'Pièce demandée <script>privée</script>', 'status' => 'pending', 'due_at' => '2026-10-20T10:00:00+00:00', 'responsible_person_id' => $manager->id, 'note' => null, 'document_version_id' => null]], 'reason' => 'Préparation du dossier selon les consignes externes.'];
        $s['revision'] = $save ? $this->patchJson($s['path'], $s['input'])->assertOk()->json('data') : null;

        return $s;
    }
}
