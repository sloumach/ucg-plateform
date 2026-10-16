<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\MediaItem;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MediaPolicyTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string, array{list<string>, bool, bool}> */
    public static function grants(): array
    {
        return ['none' => [[], false, false], 'reader' => [['media.read'], true, false], 'writer only' => [['media.write'], false, false],
            'editor' => [['media.read', 'media.write'], true, true], 'publisher only' => [['media.publish'], false, false],
            'sport' => [['sports.read', 'sports.publish'], false, false], 'partner' => [['partnerships.read', 'partnerships.write'], false, false],
            'HR' => [['administrative.read', 'administrative.write'], false, false], 'finance' => [['financial.read', 'financial.write'], false, false]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('grants')]
    public function test_explicit_permissions_without_implicit_other_business_access(array $permissions, bool $read, bool $write): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $this->assertSame($read, Gate::forUser($user)->allows('viewAny', [MediaItem::class, $context]));
        $this->assertSame($write, Gate::forUser($user)->allows('manage', [MediaItem::class, $context]));
        $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows('viewAny', [MediaItem::class, $context]));
    }

    public function test_owner_and_technical_superadmin_have_no_implicit_media_rights(): void
    {
        $org = Organization::factory()->create();
        $owner = User::findOrFail($org->owner_user_id);
        $this->assertFalse(Gate::forUser($owner)->allows('viewAny', [MediaItem::class, $this->personContext($org, $owner)]));
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['media.read', 'media.write', 'media.publish']);
        PlatformRole::factory()->superadmin()->create(['user_id' => $user->id]);
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', [MediaItem::class, $this->personContext($org, $user)]));
    }
}
