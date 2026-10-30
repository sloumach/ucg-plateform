<?php

namespace Tests\Feature;

use App\Contracts\Operations\DependencyTransport;
use App\Support\Operations\DependencyDiagnostics;
use App\Support\Operations\ProbeState;
use App\Support\Operations\SocketDependencyTransport;
use App\Support\Operations\SupervisionDiagnostics;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SupervisionDiagnosticsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_no_probe_does_not_connect_even_when_external_option_is_present(): void
    {
        $transport = $this->mock(DependencyTransport::class);
        $transport->shouldNotReceive('ping');
        $transport->shouldNotReceive('connections');
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertSame(2, Artisan::call('ops:inspect-supervision', ['--external' => true, '--json' => true]));

        $this->assertStringContainsString('PROBE_NOT_REQUESTED', Artisan::output());
        $this->assertSame(0, $queries);
    }

    public function test_local_probe_does_not_connect_to_external_dependencies_or_persist_alerts(): void
    {
        $this->configureServices();
        DB::table('operations_alerts')->count();
        $transport = $this->mock(DependencyTransport::class);
        $transport->shouldNotReceive('ping');
        $transport->shouldNotReceive('connections');

        $this->assertSame(2, Artisan::call('ops:inspect-supervision', ['--probe' => true, '--json' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('EXTERNAL_PROBE_NOT_REQUESTED', $output);
        $this->assertStringContainsString('"alerts_recorded":false', $output);
        $this->assertDatabaseCount('operations_alerts', 0);
    }

    public function test_external_protocol_checks_never_claim_delivery_or_scanning_success(): void
    {
        $this->configureServices();
        config(['broadcasting.default' => 'reverb', 'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.fixture.test', 'mail.mailers.smtp.port' => 2525,
            'mail.mailers.smtp.scheme' => null, 'mail.mailers.smtp.password' => 'sensitive-sentinel',
            'contract_documents.clamav.enabled' => true, 'contract_documents.clamav.host' => 'scanner.fixture.test',
            'contract_documents.clamav.port' => 3310]);
        $transport = $this->mock(DependencyTransport::class);
        $transport->shouldReceive('connections')->once()->andReturn(4);
        $transport->shouldReceive('ping')->once()->with('smtp', 'smtp.fixture.test', 2525, false)->andReturn(true);
        $transport->shouldReceive('ping')->once()->with('scanner', 'scanner.fixture.test', 3310, false)->andReturn(false);

        $checks = app(DependencyDiagnostics::class)->inspect(true);

        $this->assertSame(4, $checks['dependency_realtime']->observations['connections_observed']);
        $this->assertFalse($checks['dependency_realtime']->observations['private_channels_verified']);
        $this->assertSame(ProbeState::Unverified, $checks['dependency_mail']->state);
        $this->assertSame('MAIL_PROTOCOL_OBSERVED', $checks['dependency_mail']->code);
        $this->assertSame('SCANNER_UNREACHABLE', $checks['dependency_scanner']->code);
        $this->assertStringNotContainsString('sensitive-sentinel', json_encode(array_map(fn ($check) => $check->toArray(), $checks), JSON_THROW_ON_ERROR));
    }

    public function test_dependency_exception_is_redacted_and_does_not_mask_other_checks(): void
    {
        $this->configureServices();
        config(['broadcasting.default' => 'reverb']);
        $this->mock(DependencyTransport::class)->shouldReceive('connections')->once()->andThrow(new RuntimeException('sensitive-sentinel host secret'));

        $checks = app(DependencyDiagnostics::class)->inspect(true);

        $this->assertSame('REALTIME_PROBE_FAILED', $checks['dependency_realtime']->code);
        $this->assertSame('SCANNER_DISABLED', $checks['dependency_scanner']->code);
        $this->assertSame('SMTP_NOT_CONFIGURED', $checks['dependency_mail']->code);
        $this->assertStringNotContainsString('sensitive-sentinel', json_encode(array_map(fn ($check) => $check->toArray(), $checks), JSON_THROW_ON_ERROR));
    }

    public function test_s3_probe_only_checks_a_fresh_missing_key_without_reading_or_writing_documents(): void
    {
        $this->configureServices();
        config(['filesystems.artifacts' => 's3']);
        $operator = Mockery::mock(FilesystemOperator::class);
        $this->assertInstanceOf(FilesystemOperator::class, $operator);
        $operator->shouldReceive('fileExists')->once()->with(Mockery::pattern('/\A__ops_probe_[a-f0-9-]{36}\z/'))->andReturn(false);
        $operator->shouldNotReceive('has');
        $operator->shouldNotReceive('directoryExists');
        $disk = new FilesystemAdapter($operator, Storage::disk('ops-services')->getAdapter());
        $this->mock(Factory::class)->shouldReceive('disk')->once()->with('s3')->andReturn($disk);

        $check = app(DependencyDiagnostics::class)->inspect(true)['dependency_storage'];

        $this->assertSame('STORAGE_HEAD_OBSERVED', $check->code);
        $this->assertSame(ProbeState::Unverified, $check->state);
        $this->assertFalse($check->observations['write_acl_capacity_verified']);
    }

    public function test_invalid_socket_target_is_rejected_before_connecting(): void
    {
        $this->expectExceptionMessage('DEPENDENCY_TARGET_INVALID');
        app(SocketDependencyTransport::class)->ping('scanner', 'sensitive-sentinel/invalid', 3310, false);
    }

    #[TestWith(['587', null, false])]
    #[TestWith(['465', null, true])]
    #[TestWith(['465', 'smtp', false])]
    #[TestWith(['587', 'smtps', true])]
    public function test_smtp_environment_ports_and_tls_match_the_mail_transport(string $port, ?string $scheme, bool $tls): void
    {
        $this->configureServices();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.fixture.test',
            'mail.mailers.smtp.port' => $port, 'mail.mailers.smtp.scheme' => $scheme, 'mail.mailers.smtp.url' => null]);
        $this->mock(DependencyTransport::class)->shouldReceive('ping')->once()
            ->with('smtp', 'smtp.fixture.test', (int) $port, $tls)->andReturn(true);

        $this->assertSame('MAIL_PROTOCOL_OBSERVED', app(DependencyDiagnostics::class)->inspect(true)['dependency_mail']->code);
    }

    public function test_smtp_url_overrides_are_used_without_exposing_credentials(): void
    {
        $this->configureServices();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => 'smtp://fixture:sensitive-sentinel@smtp.fixture.test:2526?scheme=smtps']);
        $this->mock(DependencyTransport::class)->shouldReceive('ping')->once()
            ->with('smtp', 'smtp.fixture.test', 2526, true)->andReturn(true);

        $check = app(DependencyDiagnostics::class)->inspect(true)['dependency_mail'];

        $this->assertSame('MAIL_PROTOCOL_OBSERVED', $check->code);
        $this->assertStringNotContainsString('sensitive-sentinel', json_encode($check->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_simulated_fault_creates_one_alert_resolves_and_reopens_without_any_notification(): void
    {
        $this->configureServices();
        config(['operations.alerts.enabled' => true]);
        Mail::fake();
        Notification::fake();
        $root = config('filesystems.disks.ops-services.root');
        config(['filesystems.disks.ops-services.root' => public_path()]);
        $parameters = ['--probe' => true, '--record-alerts' => true, '--json' => true];

        $codes = [Artisan::call('ops:inspect-supervision', $parameters), Artisan::call('ops:inspect-supervision', $parameters)];
        $this->assertSame(1, DB::table('operations_alerts')->where('status', 'open')->count());
        $this->assertStringContainsString('"alert_dispatched":false', Artisan::output());
        config(['filesystems.disks.ops-services.root' => $root]);
        $codes[] = Artisan::call('ops:inspect-supervision', $parameters);
        $this->assertSame(1, DB::table('operations_alerts')->where('status', 'resolved')->count());
        config(['filesystems.disks.ops-services.root' => public_path()]);
        $codes[] = Artisan::call('ops:inspect-supervision', $parameters);
        $this->assertSame([1, 1, 2, 1], $codes);
        $this->assertDatabaseCount('operations_alerts', 1);
        $this->assertSame(1, DB::table('operations_alert_events')->where('action', 'reopened')->count());
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_api_disabled_and_missing_external_evidence_never_validate_supervision(): void
    {
        $this->configureServices();
        $report = app(SupervisionDiagnostics::class)->inspect(true, false)->toArray();

        $this->assertFalse($report['supervision_validated']);
        $this->assertFalse($report['alert_dispatched']);
        $this->assertSame('API_TELEMETRY_DISABLED', $report['checks']['api_http']['code']);
    }

    private function configureServices(): void
    {
        $disk = Storage::fake('ops-services');
        config(['operations.resource_namespace' => 'ops_services_test', 'operations.background.enabled' => false,
            'operations.api.enabled' => false, 'operations.alerts.enabled' => false,
            'broadcasting.default' => 'null', 'mail.default' => 'array', 'contract_documents.clamav.enabled' => false,
            'filesystems.artifacts' => 'ops-services', 'filesystems.disks.ops-services.driver' => 'local',
            'filesystems.disks.ops-services.root' => $disk->path('')]);
    }
}
