<?php

namespace App\Modules\Reporting\Infrastructure\Processes;

use App\Modules\Reporting\Domain\Contracts\DataRehearsalRunner;
use DOMDocument;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class PhpunitDataRehearsalRunner implements DataRehearsalRunner
{
    public function __construct(private Filesystem $files) {}

    public function run(): array
    {
        if (! $this->files->isFile(base_path('vendor/bin/phpunit'))) {
            throw new RuntimeException('Rehearsal requires development dependencies.');
        }
        $root = storage_path('framework/testing/data-rehearsal');
        if (is_link($root)) {
            throw new RuntimeException('Unsafe rehearsal directory.');
        }
        $directory = $root.DIRECTORY_SEPARATOR.Str::uuid();
        if ($this->files->exists($directory)) {
            throw new RuntimeException('Rehearsal directory already exists.');
        }
        $this->files->makeDirectory($directory, 0700, true);
        $junit = $directory.DIRECTORY_SEPARATOR.'junit.xml';
        $results = $directory.DIRECTORY_SEPARATOR.'results.jsonl';
        $process = new Process([PHP_BINARY, base_path('vendor/bin/phpunit'), '--configuration', base_path('phpunit-rehearsal.xml'),
            '--no-progress', '--colors=never', '--log-junit', $junit], base_path(), [
                'UCG_DATA_REHEARSAL_CHILD' => '1', 'UCG_DATA_REHEARSAL_RESULTS' => $results,
            ]);
        $process->setTimeout(180);
        $exit = $process->run();
        if (! $this->files->isFile($junit) || $this->files->size($junit) > 1048576) {
            throw new RuntimeException('Rehearsal evidence is missing or too large.');
        }
        $xml = $this->files->get($junit);
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (str_contains($xml, '<!DOCTYPE') || ! $document->loadXML($xml, LIBXML_NONET)) {
                throw new RuntimeException('Invalid rehearsal evidence.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $cases = $document->getElementsByTagName('testcase');
        $assertions = 0;
        foreach ($cases as $case) {
            $assertions += (int) $case->getAttribute('assertions');
        }
        $checks = [];
        if ($this->files->isFile($results)) {
            if ($this->files->size($results) > 65536) {
                throw new RuntimeException('Rehearsal evidence is too large.');
            }
            foreach (explode("\n", trim($this->files->get($results))) as $line) {
                $value = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                if (! is_array($value) || ! is_string($value['check'] ?? null) || ! is_array($value['facts'] ?? null)) {
                    throw new RuntimeException('Invalid rehearsal facts.');
                }
                foreach ($value['facts'] as $key => $fact) {
                    if (! is_string($key) || (! is_int($fact) && ! is_bool($fact))) {
                        throw new RuntimeException('Invalid rehearsal facts.');
                    }
                }
                $checks[] = ['check' => $value['check'], 'facts' => $value['facts']];
            }
        }
        $digest = hash_init('sha256');
        foreach (['phpunit-rehearsal.xml', 'tests/Rehearsal/bootstrap.php', 'tests/Rehearsal/DataRehearsalScenarioTest.php',
            'tests/Fixtures/ProvidesObservations.php', 'tests/Fixtures/ProvidesMetricCatalogue.php', 'tests/Fixtures/ProvidesPeopleAccess.php',
            'tests/Fixtures/AuthenticatesWithMfa.php', 'tests/TestCase.php', 'composer.lock',
            'app/Modules/Reporting/Application/Services/DataRehearsalService.php'] as $file) {
            hash_update($digest, $file."\0".str_replace("\r\n", "\n", $this->files->get(base_path($file)))."\0");
        }

        return ['exit_code' => $exit, 'tests' => $cases->length, 'assertions' => $assertions,
            'failures' => $document->getElementsByTagName('failure')->length, 'errors' => $document->getElementsByTagName('error')->length,
            'skipped' => $document->getElementsByTagName('skipped')->length, 'checks' => $checks, 'scenario_sha256' => hash_final($digest)];
    }
}
