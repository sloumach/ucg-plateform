<?php

namespace Tests\Feature;

use App\Modules\People\Application\Contracts\EligibilityPeopleDirectory;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Domain\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesLineups;
use Tests\TestCase;

class PersonBirthDateTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesLineups;

    public function test_sports_writer_cannot_set_or_read_a_private_birth_date(): void
    {
        [$org,$user] = $this->lineupEditor(['sports.read', 'sports.write']);
        $this->actingAsWithMfa($user);
        $path = '/api/v1/tenants/'.$org->id.'/people';
        $this->postJson($path, [...$this->personPayload(), 'birth_date' => '2000-01-01'])->assertForbidden();
        $person = Person::factory()->create(['organization_id' => $org->id, 'birth_date' => '2000-01-01']);
        $this->getJson($path.'/'.$person->id)->assertOk()->assertJsonMissingPath('data.birth_date');
    }

    public function test_profile_projection_is_bounded_per_person_with_constant_query_count(): void
    {
        [$org,$user,,$period] = $this->lineupEditor();
        $first = Person::findOrFail($period->person_id);
        $second = Person::factory()->create(['organization_id' => $org->id]);
        foreach ([$first, $second] as $person) {
            foreach (['eu', 'na', 'apac'] as $region) {
                GameProfile::factory()->create(['person_id' => $person->id, 'region_key' => $region]);
            }
        }
        [$single,$oneCount] = $this->projectionQueries($org->id, [$first->id]);
        [$both,$twoCount] = $this->projectionQueries($org->id, [$first->id, $second->id]);
        $this->assertCount(1, $single);
        $this->assertCount(2, $both);
        $this->assertSame(2, $oneCount);
        $this->assertSame($oneCount, $twoCount);
        foreach ($both as $person) {
            $this->assertCount(2, $person->gameProfiles);
            $this->assertArrayNotHasKey('birth_date', $person->getAttributes());
        }
        $this->assertDatabaseCount('person_game_profiles', 6);
    }

    /** @param list<string> $ids
     * @return array{list<Person>,int}
     */
    private function projectionQueries(string $org, array $ids): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $rows = app(PersonRepository::class)->eligibilitySubjects($org, $ids, 'valorant', 'pc', false);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$rows, $count];
    }

    /** @return array<string,array{string,string,string}> */
    public static function ages(): array
    {
        return [
            'day before birthday' => ['2008-10-14', '2026-10-13', 'failed'], 'birthday' => ['2008-10-13', '2026-10-13', 'passed'],
            'leap before' => ['2008-02-29', '2026-02-28', 'failed'], 'leap attained' => ['2008-02-29', '2026-03-01', 'passed'],
            'unknown' => ['', '2026-10-13', 'unknown'],
        ];
    }

    #[DataProvider('ages')]
    public function test_private_age_is_a_boolean_projection_at_calendar_boundaries(string $birth, string $at, string $expected): void
    {
        [$org,$user,,$period] = $this->lineupEditor(['sports.read', 'teams.manage', 'administrative.read']);
        $person = Person::findOrFail($period->person_id);
        $person->forceFill(['birth_date' => $birth === '' ? null : $birth])->save();
        $facts = app(EligibilityPeopleDirectory::class)->facts($this->personContext($org, $user), [$person->id], 'valorant', null, CarbonImmutable::parse($at), 18, null);
        $this->assertSame($expected, $facts[$person->id]['age_status']);
        $this->assertArrayNotHasKey('birth_date', $facts[$person->id]);
        $this->assertArrayNotHasKey('age', $facts[$person->id]);
        $this->assertArrayNotHasKey('birth_date', $person->toArray());
        if ($birth !== '') {
            $this->assertNotSame($birth, DB::table('people')->where('id', $person->id)->value('birth_date'));
        }
    }

    public function test_birth_date_requires_administrative_write_and_is_validated(): void
    {
        [$org,$user] = $this->lineupEditor(['sports.read', 'sports.write', 'administrative.read', 'administrative.write']);
        $this->actingAsWithMfa($user);
        $path = '/api/v1/tenants/'.$org->id.'/people';
        $this->postJson($path, [...$this->personPayload(), 'birth_date' => '2000-02-30'])->assertUnprocessable()->assertJsonValidationErrors('birth_date');
        $this->postJson($path, [...$this->personPayload(), 'birth_date' => '2026-10-14'])->assertUnprocessable()->assertJsonValidationErrors('birth_date');
        $this->postJson($path, [...$this->personPayload(), 'birth_date' => '2000-02-29'])->assertCreated()->assertJsonPath('data.birth_date', '2000-02-29');
    }
}
