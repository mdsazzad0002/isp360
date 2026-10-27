<?php

namespace App\Services\Network\Cli;

use App\Models\Olt;

// Opens the CLI link to an OLT (bound to a fake in tests).
class CliConnector
{
    public function connect(Olt $olt): CliTransport
    {
        $timeout = (float) config('isp.olt.cli_timeout', 15);
        return $olt->cli_protocol === 'ssh'
            ? new SshTransport($olt->host, (int) ($olt->cli_port ?: 22), (string) $olt->cli_username, (string) $olt->cli_password, $timeout)
            : new TelnetTransport($olt->host, (int) ($olt->cli_port ?: 23), $timeout);
    }
}
