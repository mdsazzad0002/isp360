<?php

namespace App\Services\Network;

use RuntimeException;
use Symfony\Component\Process\Process;

// Checks run from THIS server, for connections without a router (static IP, no router, router down).
// Never a shell: fixed binaries, the target validated as an IP or host name and passed as one argument.
// The server only reaches customer IPs that are routed to it; a private PPPoE address behind the
// router usually is not, and then the router's own commands are the right test.
class ServerProbe
{
    public static function target(?string $target): string
    {
        $target = trim((string) $target);
        if ($target === '' || ! (filter_var($target, FILTER_VALIDATE_IP) || preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $target))) {
            throw new RuntimeException('Give an IP address or host name.');
        }
        return $target;
    }

    /** @return array{lines: string[], sent: int, received: int, loss: int, rtt: ?string} */
    public static function ping(string $target, int $count): array
    {
        $count = max(1, min(10, $count));
        $p = new Process(['ping', '-n', '-c', (string) $count, '-W', '2', '-i', '0.5', self::target($target)]);
        $p->setTimeout(5 + $count * 3);
        $p->run();
        $text = trim($p->getOutput() . "\n" . $p->getErrorOutput());
        if (! preg_match('/(\d+) packets transmitted, (\d+) received/', $text, $m)) {
            throw new RuntimeException('Ping failed: ' . (strtok($text, "\n") ?: 'no output'));
        }
        preg_match('/= ([\d.]+\/[\d.]+\/[\d.]+)/', $text, $rtt);
        $lines = array_values(array_filter(explode("\n", $text), fn ($l) => str_contains($l, 'bytes from') || str_contains($l, 'Unreachable')));
        return ['lines' => $lines, 'sent' => (int) $m[1], 'received' => (int) $m[2], 'loss' => $m[1] ? (int) round(100 - $m[2] * 100 / $m[1]) : 100, 'rtt' => $rtt[1] ?? null];
    }

    /** Opens a TCP connection (no data sent). @return array{open: bool, ms: ?int, error: ?string} */
    public static function tcp(string $target, int $port): array
    {
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Port must be 1-65535.');
        }
        $start = microtime(true);
        $fp = @fsockopen(self::target($target), $port, $errno, $error, 3);
        if (! $fp) {
            return ['open' => false, 'ms' => null, 'error' => $error ?: 'no answer'];
        }
        fclose($fp);
        return ['open' => true, 'ms' => (int) round((microtime(true) - $start) * 1000), 'error' => null];
    }

    /** @return string[] hop lines */
    public static function trace(string $target): array
    {
        $p = new Process(['tracepath', '-n', '-m', '15', self::target($target)]);
        $p->setTimeout(40);
        $p->run();
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $p->getOutput() . $p->getErrorOutput()))));
        if (! $lines) {
            throw new RuntimeException('Trace failed: no output.');
        }
        return $lines;
    }
}
