<?php

namespace App\Services\Network\Cli;

// A byte stream to a device's command line (SSH shell or Telnet).
interface CliTransport
{
    public function write(string $data): void;

    // Whatever arrived within $timeout seconds ('' when nothing did). Throws when the link is closed.
    public function read(float $timeout): string;

    // Line ending the device expects
    public function newline(): string;

    public function close(): void;
}
