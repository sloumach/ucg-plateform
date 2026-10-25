<?php

namespace App\Modules\Contracts\Infrastructure\Scanning;

use App\Modules\Contracts\Domain\Contracts\ContractDocumentScanner;
use App\Modules\Contracts\Domain\DocumentSecurity;
use App\Modules\Contracts\Domain\ScanVerdict;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final readonly class ClamAvDocumentScanner implements ContractDocumentScanner
{
    private const int CHUNK_BYTES = 65536;

    private const int REPLY_BYTES = 512;

    public function __construct(private Repository $config) {}

    public function scan(string $contents): ScanVerdict
    {
        if (! DocumentSecurity::pdf($contents)) {
            return new ScanVerdict('rejected', 'invalid_pdf');
        }
        if ($this->config->get('contract_documents.clamav.enabled') !== true) {
            return new ScanVerdict('failed', 'scanner_unavailable');
        }
        try {
            $version = $this->exchange("zVERSION\0");
            if (preg_match('~\AClamAV ([a-zA-Z0-9.+_-]{1,40})/([0-9]{1,12})/(.{1,100})\z~D', $version, $matches) !== 1) {
                return new ScanVerdict('failed', 'scanner_response');
            }
            $dateText = preg_replace('/\s+/', ' ', trim($matches[3]));
            if (! is_string($dateText)) {
                return new ScanVerdict('failed', 'scanner_response');
            }
            $date = DateTimeImmutable::createFromFormat('!D M j H:i:s Y', $dateText, new DateTimeZone('UTC'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) {
                return new ScanVerdict('failed', 'scanner_response', $version);
            }
            $maxAge = $this->config->get('contract_documents.clamav.max_database_age_seconds');
            $age = $date === false ? null : CarbonImmutable::now('UTC')->getTimestamp() - $date->getTimestamp();
            if (! is_int($maxAge) || $maxAge < 1 || $maxAge > 604800 || $age === null || $age < -3600 || $age > $maxAge) {
                return new ScanVerdict('failed', 'scanner_database_stale', $version);
            }
            $reply = $this->exchange("zINSTREAM\0", $contents);
            if ($reply === 'stream: OK') {
                return new ScanVerdict('clean', engine: $version);
            }
            if (preg_match('/\Astream: [^\r\n\x00]{1,180} FOUND\z/D', $reply) === 1) {
                return new ScanVerdict('rejected', 'malware', $version);
            }

            return new ScanVerdict('failed', 'scanner_response', $version);
        } catch (RuntimeException) {
            return new ScanVerdict('failed', 'scanner_unavailable');
        }
    }

    private function exchange(string $command, ?string $contents = null): string
    {
        $host = $this->config->get('contract_documents.clamav.host');
        $port = $this->config->get('contract_documents.clamav.port');
        $connect = $this->config->get('contract_documents.clamav.connect_seconds');
        $operation = $this->config->get('contract_documents.clamav.operation_seconds');
        if (! is_string($host) || preg_match('/\A[a-zA-Z0-9.-]{1,253}\z/D', $host) !== 1
            || ! is_int($port) || $port < 1 || $port > 65535 || ! is_int($connect) || $connect < 1 || $connect > 3
            || ! is_int($operation) || $operation < 1 || $operation > 20) {
            throw new RuntimeException('Document scanner configuration is invalid.');
        }
        $socket = @stream_socket_client('tcp://'.$host.':'.$port, $errorNumber, $errorMessage, $connect);
        if ($socket === false) {
            throw new RuntimeException('Document scanner connection unavailable.');
        }
        try {
            stream_set_blocking($socket, false);
            $deadline = hrtime(true) + $operation * 1000000000;
            $this->writeAll($socket, $command, $deadline);
            if ($contents !== null) {
                for ($offset = 0; $offset < strlen($contents); $offset += self::CHUNK_BYTES) {
                    $chunk = substr($contents, $offset, self::CHUNK_BYTES);
                    $this->writeAll($socket, pack('N', strlen($chunk)).$chunk, $deadline);
                }
                $this->writeAll($socket, pack('N', 0), $deadline);
            }
            $reply = '';
            while (strlen($reply) <= self::REPLY_BYTES) {
                $this->ready($socket, false, $deadline);
                $chunk = @fread($socket, self::REPLY_BYTES + 1 - strlen($reply));
                if ($chunk === false) {
                    throw new RuntimeException('Document scanner reply incomplete.');
                }
                if ($chunk === '') {
                    // Non-session clamd commands end by closing the connection.
                    // A terminated OK record is provisional until EOF: another
                    // verdict may arrive in a later TCP packet.
                    $end = strpos($reply, "\0");
                    if (feof($socket) && $end !== false && $end === strlen($reply) - 1) {
                        return substr($reply, 0, $end);
                    }
                    throw new RuntimeException('Document scanner reply incomplete.');
                }
                $reply .= $chunk;
                $end = strpos($reply, "\0");
                if ($end !== false) {
                    if ($end !== strlen($reply) - 1) {
                        throw new RuntimeException('Document scanner reply ambiguous.');
                    }
                }
            }
            throw new RuntimeException('Document scanner reply exceeds its bound.');
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function writeAll($socket, string $bytes, int $deadline): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $this->ready($socket, true, $deadline);
            $written = @fwrite($socket, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Document scanner write failed.');
            }
            $offset += $written;
        }
    }

    /** @param resource $socket */
    private function ready($socket, bool $writing, int $deadline): void
    {
        $remaining = $deadline - hrtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('Document scanner deadline exceeded.');
        }
        $read = $writing ? [] : [$socket];
        $write = $writing ? [$socket] : [];
        $except = [];
        if (@stream_select($read, $write, $except, intdiv($remaining, 1000000000), (int) (($remaining % 1000000000) / 1000)) !== 1) {
            throw new RuntimeException('Document scanner deadline or transport failure.');
        }
    }
}
