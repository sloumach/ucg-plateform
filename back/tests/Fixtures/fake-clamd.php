<?php

// A local protocol peer, never an antivirus substitute. It receives bytes only in memory.
$mode = $argv[1] ?? 'clean';
$server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
if ($server === false) {
    exit(2);
}
echo stream_socket_get_name($server, false)."\n";
flush();
$version = 'ClamAV test/123/'.($mode === 'invalid-date' ? 'Thu Feb 30 00:00:00 2026'
    : gmdate('D M j H:i:s Y', time() + ($mode === 'future' ? 7200 : ($mode === 'stale' ? -700000 : -60))));
$read = static function ($socket, int $bytes): string {
    $result = '';
    while (strlen($result) < $bytes) {
        $chunk = fread($socket, max(1, $bytes - strlen($result)));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Incomplete test stream.');
        }
        $result .= $chunk;
    }

    return $result;
};
try {
    $peer = stream_socket_accept($server, 5);
    if ($peer === false) {
        exit(3);
    }
    $command = $read($peer, 9);
    if ($command !== "zVERSION\0") {
        exit(4);
    }
    fwrite($peer, ($mode === 'bad-version' ? 'Unknown daemon' : $version)."\0");
    fclose($peer);
    if (in_array($mode, ['stale', 'bad-version', 'future', 'invalid-date'], true)) {
        exit(0);
    }
    $peer = stream_socket_accept($server, 5);
    if ($peer === false || $read($peer, 10) !== "zINSTREAM\0") {
        exit(5);
    }
    $hash = hash_init('sha256');
    $total = 0;
    $chunks = 0;
    while (true) {
        $header = unpack('Nlength', $read($peer, 4));
        if ($header === false || ! isset($header['length']) || ! is_int($header['length'])) {
            exit(7);
        }
        $length = $header['length'];
        if ($length === 0) {
            break;
        }
        if ($length > 65536) {
            exit(6);
        }
        hash_update($hash, $read($peer, $length));
        $total += $length;
        $chunks++;
    }
    $reply = match ($mode) {
        'malware' => 'stream: Test.Signature FOUND',
        'error' => 'INSTREAM size limit exceeded. ERROR',
        'ambiguous' => "stream: OK\0stream: Test FOUND",
        'overlong' => str_repeat('X', 600),
        'truncated' => '',
        default => 'stream: OK',
    };
    if ($mode === 'timeout') {
        usleep(1500000);
    }
    if ($mode === 'split-ambiguous') {
        fwrite($peer, "stream: OK\0");
        fflush($peer);
        usleep(50000);
        @fwrite($peer, "stream: Test FOUND\0");
    } elseif ($mode === 'fragmented-clean') {
        fwrite($peer, 'stream:');
        fflush($peer);
        usleep(20000);
        @fwrite($peer, " OK\0");
    } else {
        @fwrite($peer, $mode === 'truncated' ? 'stream: OK' : $reply."\0");
    }
    if ($mode === 'keep-open') {
        usleep(1500000);
    }
    fclose($peer);
    echo json_encode(['hash' => hash_final($hash), 'bytes' => $total, 'chunks' => $chunks], JSON_THROW_ON_ERROR)."\n";
} finally {
    fclose($server);
}
