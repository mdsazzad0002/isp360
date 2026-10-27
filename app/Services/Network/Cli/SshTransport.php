<?php

namespace App\Services\Network\Cli;

use phpseclib3\Net\SSH2;
use RuntimeException;

class SshTransport implements CliTransport
{
    private SSH2 $ssh;

    public function __construct(string $host, int $port, string $username, string $password, float $timeout = 10)
    {
        $this->ssh = new SSH2($host, $port, (int) ceil($timeout));
        // OLTs often only offer older key exchanges; phpseclib negotiates them itself
        if (! @$this->ssh->login($username, $password)) {
            throw new RuntimeException("SSH login to {$host}:{$port} failed (check the address, port, username and password)");
        }
        $this->ssh->setWindowSize(200, 50);
    }

    public function write(string $data): void
    {
        $this->ssh->write($data);
    }

    public function read(float $timeout): string
    {
        $this->ssh->setTimeout($timeout);
        $data = $this->ssh->read('', SSH2::READ_NEXT);
        if ($data === false) {
            throw new RuntimeException('The OLT closed the SSH session');
        }
        return is_string($data) ? $data : '';
    }

    public function newline(): string
    {
        return "\n";
    }

    public function close(): void
    {
        $this->ssh->disconnect();
    }
}
