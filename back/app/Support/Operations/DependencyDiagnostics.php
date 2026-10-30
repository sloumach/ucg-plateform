<?php

namespace App\Support\Operations;

use App\Contracts\Operations\DependencyTransport;
use App\Contracts\Operations\RuntimeProbe;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Str;
use Throwable;

final readonly class DependencyDiagnostics
{
    public function __construct(private Repository $config, private RuntimeProbe $runtime,
        private DependencyTransport $transport, private Factory $filesystems) {}

    /** @return array<string, RuntimeCheck> */
    public function inspect(bool $external): array
    {
        $checks = [];
        foreach (['storage', 'realtime', 'mail', 'scanner'] as $component) {
            try {
                $checks['dependency_'.$component] = $this->read($component, $external);
            } catch (Throwable) {
                $checks['dependency_'.$component] = new RuntimeCheck(ProbeState::Failed, strtoupper($component).'_PROBE_FAILED');
            }
        }

        return $checks;
    }

    private function read(string $component, bool $external): RuntimeCheck
    {
        if ($component === 'storage') {
            $disk = $this->config->get('filesystems.artifacts');
            if (! is_string($disk) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_-]{0,62}\z/', $disk) !== 1) {
                return new RuntimeCheck(ProbeState::Failed, 'STORAGE_CONFIGURATION_INVALID');
            }
            $driver = $this->config->get('filesystems.disks.'.$disk.'.driver');
            if ($driver === 'local') {
                return $this->runtime->privateStorage();
            }
            if ($external && $driver === 's3') {
                $filesystem = $this->filesystems->disk($disk);
                if (! $filesystem instanceof FilesystemAdapter) {
                    return new RuntimeCheck(ProbeState::Unverified, 'STORAGE_ADAPTER_UNSUPPORTED');
                }
                $filesystem->fileExists('__ops_probe_'.Str::uuid());

                return new RuntimeCheck(ProbeState::Unverified, 'STORAGE_HEAD_OBSERVED', ['write_acl_capacity_verified' => false]);
            }

            return new RuntimeCheck(ProbeState::Unverified, 'STORAGE_REMOTE_NOT_VERIFIED');
        }
        if (! $external) {
            return new RuntimeCheck(ProbeState::Unverified, 'EXTERNAL_PROBE_NOT_REQUESTED');
        }
        if ($component === 'realtime') {
            if ($this->config->get('broadcasting.default') !== 'reverb') {
                return new RuntimeCheck(ProbeState::Unverified, 'REALTIME_DISABLED');
            }

            return new RuntimeCheck(ProbeState::Passed, 'REALTIME_CONNECTIONS_READ_PASSED',
                ['connections_observed' => $this->transport->connections(), 'private_channels_verified' => false]);
        }
        if ($component === 'scanner') {
            if ($this->config->get('contract_documents.clamav.enabled') !== true) {
                return new RuntimeCheck(ProbeState::Unverified, 'SCANNER_DISABLED');
            }
            $host = $this->config->get('contract_documents.clamav.host');
            $port = $this->config->get('contract_documents.clamav.port');
            $tls = false;
            $service = 'scanner';
        } else {
            $mailer = $this->config->get('mail.default');
            $settings = is_string($mailer) && preg_match('/\A[a-zA-Z0-9_-]{1,63}\z/', $mailer) === 1
                ? $this->config->get('mail.mailers.'.$mailer) : null;
            if (! is_array($settings)) {
                return new RuntimeCheck(ProbeState::Unverified, 'SMTP_NOT_CONFIGURED');
            }
            if (isset($settings['url'])) {
                $settings = (new ConfigurationUrlParser)->parseConfiguration($settings);
                $settings['transport'] = $settings['driver'] ?? null;
            }
            if (($settings['transport'] ?? null) !== 'smtp') {
                return new RuntimeCheck(ProbeState::Unverified, 'SMTP_NOT_CONFIGURED');
            }
            $host = $settings['host'] ?? null;
            $port = $settings['port'] ?? null;
            $scheme = $settings['scheme'] ?? null;
            $tls = $scheme === 'smtps' || (! $scheme && (string) $port === '465');
            $service = 'smtp';
        }
        $port = is_string($port) || is_int($port)
            ? filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) : false;
        if (! is_string($host) || ! is_int($port)) {
            return new RuntimeCheck(ProbeState::Failed, 'DEPENDENCY_CONFIGURATION_INVALID');
        }
        $reached = $this->transport->ping($service, $host, $port, $tls);

        return new RuntimeCheck($reached ? ProbeState::Unverified : ProbeState::Failed,
            $reached ? strtoupper($component).'_PROTOCOL_OBSERVED' : strtoupper($component).'_UNREACHABLE',
            ['business_delivery_verified' => false]);
    }
}
