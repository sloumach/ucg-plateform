<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CalendarFeedPolicyTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string,array{list<string>,bool}> */
    public static function permissions(): array
    {
        return ['none' => [[], false], 'reader' => [['calendar.read'], false], 'exporter alone' => [['calendar.export'], false],
            'writer' => [['calendar.read', 'calendar.write'], false], 'exporter' => [['calendar.read', 'calendar.export'], true]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function test_export_requires_read_and_explicit_export_and_correct_actor(array $permissions, bool $allowed): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $this->assertSame($allowed, Gate::forUser($user)->allows('export', [CalendarActivity::class, $context]));
        $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows('export', [CalendarActivity::class, $context]));
        $owner = User::query()->findOrFail($org->owner_user_id);
        $this->assertFalse(Gate::forUser($owner)->allows('export', [CalendarActivity::class, $this->personContext($org, $owner)]));
    }
}
