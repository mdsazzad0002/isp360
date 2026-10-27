<?php

namespace App\Services\Network\Cli;

use RuntimeException;

// Plain Telnet: refuses every option the device offers (a bare NVT line is all the CLI needs) and
// strips the negotiation bytes out of what it reads.
class TelnetTransport implements CliTransport
{
    private const IAC = 255;
    private const DONT = 254;
    private const DO = 253;
    private const WONT = 252;
    private const WILL = 251;
    private const SB = 250;
    private const SE = 240;

    /** @var resource */
    private $socket;
    private string $pending = ''; // an IAC sequence split across two reads

    public function __construct(string $host, int $port, float $timeout = 10)
    {
        $socket = @stream_socket_client('tcp://' . (str_contains($host, ':') ? "[{$host}]" : $host) . ":{$port}", $errno, $error, $timeout);
        if (! $socket) {
            throw new RuntimeException("Telnet to {$host}:{$port} failed ({$error})");
        }
        $this->socket = $socket;
    }

    public function write(string $data): void
    {
        // a literal 0xFF byte is doubled on the wire
        fwrite($this->socket, str_replace(chr(self::IAC), chr(self::IAC) . chr(self::IAC), $data));
    }

    public function read(float $timeout): string
    {
        $read = [$this->socket];
        $write = $except = null;
        if (! stream_select($read, $write, $except, (int) $timeout, (int) (fmod($timeout, 1) * 1e6))) {
            return '';
        }
        $data = fread($this->socket, 8192);
        if ($data === '' || $data === false) {
            if (feof($this->socket)) {
                throw new RuntimeException('The OLT closed the Telnet session');
            }
            return '';
        }
        return $this->negotiate($this->pending . $data);
    }

    private function negotiate(string $data): string
    {
        $this->pending = '';
        $out = '';
        $reply = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($data[$i]);
            if ($c !== self::IAC) {
                $out .= $data[$i];
                continue;
            }
            if ($i + 1 >= $len) {
                $this->pending = substr($data, $i);
                break;
            }
            $cmd = ord($data[$i + 1]);
            if ($cmd === self::IAC) {
                $out .= chr(self::IAC);
                $i++;
            } elseif (in_array($cmd, [self::DO, self::DONT, self::WILL, self::WONT], true)) {
                if ($i + 2 >= $len) {
                    $this->pending = substr($data, $i);
                    break;
                }
                $option = $data[$i + 2];
                if ($cmd === self::DO) {
                    $reply .= chr(self::IAC) . chr(self::WONT) . $option;
                } elseif ($cmd === self::WILL) {
                    $reply .= chr(self::IAC) . chr(self::DONT) . $option;
                }
                $i += 2;
            } elseif ($cmd === self::SB) {
                $end = strpos($data, chr(self::IAC) . chr(self::SE), $i + 2);
                if ($end === false) {
                    $this->pending = substr($data, $i);
                    break;
                }
                $i = $end + 1;
            } else {
                $i++; // two-byte command (NOP, GA, …)
            }
        }
        if ($reply !== '') {
            fwrite($this->socket, $reply);
        }
        return $out;
    }

    public function newline(): string
    {
        return "\r\n";
    }

    public function close(): void
    {
        @fclose($this->socket);
    }
}
