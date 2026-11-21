<?php

namespace Database\Seeders;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Application\Validation\PersonInput;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Application\Services\TeamMembershipService;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Data\InvitationData;
use App\Modules\Tenancy\Application\Data\RoleAssignmentData;
use App\Modules\Tenancy\Application\Data\RoleData;
use App\Modules\Tenancy\Application\Services\InvitationService;
use App\Modules\Tenancy\Application\Services\RoleManagementService;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\InvitationStatus;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class LocalUcgTestAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $this->requireLocal();
        $path = base_path('reports/ucg-test-accounts.json');
        if (! is_file($path)) {
            throw new RuntimeException('Le fichier privé reports/ucg-test-accounts.json est requis.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Le fichier privé des comptes est illisible.');
        }
        $credentials = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($credentials)) {
            throw new RuntimeException('Le fichier privé doit contenir les identifiants des comptes.');
        }
        $this->seedAccounts($credentials);
    }

    /** Credentials come from a private, Git-ignored file, never from committed seed data.
     * @param  array<string, mixed>  $credentials
     */
    public function seedAccounts(array $credentials): void
    {
        $this->requireLocal();
        $profiles = $this->profiles();
        if (array_diff(array_keys($profiles), array_keys($credentials)) !== [] || array_diff(array_keys($credentials), array_keys($profiles)) !== []) {
            throw new RuntimeException('Les douze comptes de test attendus doivent être fournis.');
        }
        foreach ($credentials as $password) {
            if (! is_string($password) || strlen($password) < 16 || strlen($password) > 72 || str_contains($password, "\0")) {
                throw new RuntimeException('Chaque compte de test exige un mot de passe privé de 16 à 72 octets.');
            }
        }

        DB::transaction(function () use ($credentials, $profiles): void {
            $organization = Organization::query()->where('slug', 'ucg')->where('name', 'Ultra Cyber Game')->sole();
            if (! $organization->acceptsBusinessOperations()) {
                throw new RuntimeException('Ultra Cyber Game doit être active.');
            }
            $game = GameCatalogueEntry::query()->where('kind', 'game')->where('key', 'mlbb')->where('active', true)->sole();
            $team = Team::query()->where('organization_id', $organization->id)->where('game_id', $game->id)
                ->where('slug', 'mlbb')->where('status', 'active')->sole();
            $manager = User::query()->where('email', 'manager-esports@ucg.test')->sole();
            $resolver = app(TenantResolver::class);
            $ownerContext = $resolver->resolve($organization->id, 'localhost', $organization->owner_user_id, (string) Str::uuid());
            $managerContext = $resolver->resolve($organization->id, 'localhost', $manager->id, (string) Str::uuid());
            $invitations = app(InvitationService::class);
            $roles = app(RoleManagementService::class);

            foreach ($profiles as $email => $profile) {
                $user = User::query()->where('email', $email)->lockForUpdate()->first();
                if ($user === null) {
                    $user = new User(['name' => $profile['name'], 'email' => $email, 'password' => $credentials[$email]]);
                    $user->email_verified_at = now();
                    $user->save();
                }
                if (! $user->hasVerifiedEmail()) {
                    throw new RuntimeException('Un compte existant non vérifié ne peut pas être confirmé par ce seed.');
                }
                $membership = OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->first();
                if ($membership === null) {
                    $pending = OrganizationInvitation::query()->where('organization_id', $organization->id)->where('email', $email)
                        ->where('status', InvitationStatus::Pending)->where('expires_at', '>', now())->first();
                    if ($pending !== null && $pending->roles !== ['member']) {
                        throw new RuntimeException('Une invitation existante possède des droits différents.');
                    }
                    $invitation = $pending ?? $invitations->invite($ownerContext, new InvitationData($email, ['member']));
                    $invitations->respond($invitation->id, $user->id, 'accepted', (string) Str::uuid());
                } elseif (! $membership->isEffective(now())) {
                    throw new RuntimeException('Une adhésion existante inactive nécessite une décision explicite.');
                }

                $definition = $this->roles()[$profile['role']];
                $role = OrganizationRole::query()->where('organization_id', $organization->id)->where('key', $profile['role'])->first();
                if ($role === null) {
                    $role = $roles->saveRole($ownerContext, new RoleData($profile['role'], $definition['name'], $definition['permissions'], true));
                } elseif (! $role->active || array_diff($role->permissions, $definition['permissions']) !== [] || array_diff($definition['permissions'], $role->permissions) !== []) {
                    throw new RuntimeException('Un rôle existant diffère du PDF ; aucune permission existante ne sera écrasée.');
                }
                $assignment = OrganizationRoleAssignment::query()->where('organization_id', $organization->id)->where('role_id', $role->id)
                    ->where('user_id', $user->id)->where('scope_type', 'organization')->where('scope_id', $organization->id)
                    ->where('status', 'active')->where('starts_at', '<=', now())->whereNull('ends_at')->first();
                if ($assignment === null) {
                    $roles->saveAssignment($ownerContext, new RoleAssignmentData($role->id, $user->id, 'organization', $organization->id, 'active', now()->toIso8601String(), null));
                }

                if ($profile['role'] !== 'joueur') {
                    continue;
                }
                $person = Person::withTrashed()->where('organization_id', $organization->id)->where('user_id', $user->id)->first();
                if ($person === null) {
                    // Trusted local fixture creation: do not grant administrative access to the manager to link accounts.
                    $attributes = Validator::make(['pseudonym' => $profile['name'], 'user_id' => $user->id,
                        'language' => $organization->language, 'timezone' => $organization->timezone,
                        'status' => $profile['roster'] === 'player' ? 'active' : 'substitute'], PersonInput::rules(true))->validate();
                    $person = new Person;
                    $person->forceFill([...$attributes, 'organization_id' => $organization->id, 'version' => (string) Str::uuid()])->save();
                    app(TenantAuditJournal::class)->record($organization->id, new AuditWriteData($organization->owner_user_id,
                        AuditAction::DomainChanged, AuditTarget::Organization, $organization->id, AuditReason::Domain,
                        (string) Str::uuid(), after: ['person_id' => $person->id, 'person_status' => $person->status->value, 'account_linked' => true, 'version' => $person->version]));
                }
                if ($person->trashed() || ! in_array($person->status->value, ['active', 'substitute'], true)) {
                    throw new RuntimeException('Une fiche joueur existante ne peut pas être réactivée par ce seed.');
                }
                $period = TeamMembership::query()->where('organization_id', $organization->id)->where('team_id', $team->id)
                    ->where('person_id', $person->id)->whereIn('role_key', ['player', 'substitute'])->where('status', 'active')->whereNull('ends_at')->first();
                if ($period === null) {
                    app(TeamMembershipService::class)->create($managerContext, $team->id, ['person_id' => $person->id,
                        'role_key' => $profile['roster'], 'starts_at' => now()->toIso8601String(), 'reason_key' => 'initial']);
                } elseif ($period->role_key !== $profile['roster']) {
                    throw new RuntimeException('Une affectation sportive existante diffère du seed demandé.');
                }
            }
        });
    }

    private function requireLocal(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Ce seed de comptes fictifs est réservé à un environnement local.');
        }
    }

    /** @return array<string, array{name: string, role: string, roster: string|null}> */
    private function profiles(): array
    {
        $profiles = [
            'joueur@ucg.test' => ['name' => 'Joueur MLBB 1', 'role' => 'joueur', 'roster' => 'player'],
            'analyste-performance@ucg.test' => ['name' => 'Analyste performance', 'role' => 'analyste-performance', 'roster' => null],
            'responsable-academie@ucg.test' => ['name' => 'Responsable académie', 'role' => 'responsable-academie', 'roster' => null],
            'administratif-financier@ucg.test' => ['name' => 'Responsable administratif et financier', 'role' => 'administratif-financier', 'roster' => null],
            'communication-partenariats@ucg.test' => ['name' => 'Responsable communication et partenariats', 'role' => 'communication-partenariats', 'roster' => null],
            'direction-validation@ucg.test' => ['name' => 'Direction / Validation', 'role' => 'direction-validation', 'roster' => null],
        ];
        for ($number = 2; $number <= 7; $number++) {
            $profiles['joueur-'.$number.'@ucg.test'] = ['name' => 'Joueur MLBB '.$number, 'role' => 'joueur', 'roster' => $number <= 5 ? 'player' : 'substitute'];
        }

        return $profiles;
    }

    /** Permissions transcribed from docs/exemple-roles-et-permissions-ucg.pdf.
     * @return array<string, array{name: string, permissions: list<string>}> */
    private function roles(): array
    {
        return [
            'joueur' => ['name' => 'Joueur', 'permissions' => ['reporting.player', 'sports.read', 'academy.self.read', 'calendar.read', 'calendar.export', 'calendar.invitations.respond', 'availability.self', 'communication.read', 'communication.comment', 'media.read']],
            'analyste-performance' => ['name' => 'Analyste performance', 'permissions' => ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write', 'sports.statistics.import', 'sports.statistics.correct']],
            'responsable-academie' => ['name' => 'Responsable académie', 'permissions' => ['academy.read', 'academy.manage', 'academy.attendance.manage', 'academy.feedback.manage', 'academy.notes.manage', 'sports.read', 'calendar.read']],
            'administratif-financier' => ['name' => 'Responsable administratif et financier', 'permissions' => ['reporting.administration', 'administrative.read', 'administrative.write', 'financial.read', 'financial.write', 'partnerships.read', 'partnerships.write']],
            'communication-partenariats' => ['name' => 'Responsable communication et partenariats', 'permissions' => ['communication.read', 'communication.write', 'communication.publish', 'communication.comment', 'tasks.read', 'tasks.manage', 'media.read', 'media.write', 'media.publish', 'partnerships.read', 'partnerships.write']],
            'direction-validation' => ['name' => 'Direction / Validation', 'permissions' => ['reporting.direction', 'reporting.export', 'sports.read', 'sports.validate', 'sports.publish', 'administrative.read', 'administrative.write', 'administrative.validate', 'financial.read', 'financial.validate', 'partnerships.read', 'partnerships.write', 'partnerships.validate', 'calendar.read', 'calendar.write', 'calendar.conflicts.override']],
        ];
    }
}
