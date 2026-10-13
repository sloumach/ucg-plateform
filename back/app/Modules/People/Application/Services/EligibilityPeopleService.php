<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Contracts\EligibilityPeopleDirectory;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;

final readonly class EligibilityPeopleService implements EligibilityPeopleDirectory
{
    public function __construct(private PersonRepository $people, private TenantAuthorization $authorization, private ExceptionHandler $errors, private Encrypter $encryption) {}

    public function facts(TenantContext $context, array $personIds, string $gameKey, ?string $platform, CarbonImmutable $at, ?int $minAge, ?int $maxAge): array
    {
        $this->authorization->authorize($context, 'sports.read');
        if (count($personIds) > 50) {
            throw new \LogicException('Eligibility projections are bounded to one composition.');
        }
        $ageNeeded = $minAge !== null || $maxAge !== null;
        $private = $ageNeeded && $this->authorization->allows($context, 'administrative.read');
        $result = [];
        foreach ($this->people->eligibilitySubjects($context->organizationId, $personIds, $gameKey, $platform, $private) as $person) {
            $result[$person->id] = ['age_status' => $ageNeeded ? $this->age($person, $at, $minAge, $maxAge, $private) : 'not_requested',
                'profiles' => $platform === null ? [] : array_values($person->gameProfiles->map(fn (GameProfile $profile): array => [
                    'id' => $profile->id, 'version' => $profile->version, 'region_key' => $profile->region_key, 'rank' => $profile->rank, 'observed_at' => $profile->observed_at?->toIso8601String()])->all())];
        }

        return $result;
    }

    private function age(Person $person, CarbonImmutable $at, ?int $min, ?int $max, bool $allowed): string
    {
        if (! $allowed) {
            return 'unknown';
        }
        $ciphertext = $person->getRawOriginal('birth_date');
        if (! is_string($ciphertext)) {
            return 'unknown';
        }
        try {
            $birth = $this->encryption->decryptString($ciphertext);
        } catch (DecryptException $error) {
            $this->errors->report($error);

            return 'unknown';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $birth, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $birth || $birth > $at->utc()->format('Y-m-d')) {
            return 'unknown';
        }
        // Calendar years: a 29 February birthday is reached on 1 March in non-leap years.
        $years = (int) $at->utc()->format('Y') - (int) $date->format('Y') - ($at->utc()->format('md') < $date->format('md') ? 1 : 0);

        return (($min !== null && $years < $min) || ($max !== null && $years > $max)) ? 'failed' : 'passed';
    }
}
