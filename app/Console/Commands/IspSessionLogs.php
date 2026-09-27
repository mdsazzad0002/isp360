<?php

namespace App\Console\Commands;

use App\Services\Isp\SessionLogService;
use Illuminate\Console\Command;

// Keeps the lawful session log up to date (RADIUS accounting, MikroTik polling) and prunes it to
// the retention period. Scheduled every 5 minutes; --prune runs daily.
class IspSessionLogs extends Command
{
    protected $signature = 'isp:session-logs {--prune : only delete sessions older than the retention period}';

    protected $description = 'Record internet sessions (who had which IP when) from RADIUS and MikroTik; prune old ones';

    public function handle(): int
    {
        if ($this->option('prune')) {
            $this->info(SessionLogService::prune() . ' old session(s) deleted');
            return self::SUCCESS;
        }
        $radius = SessionLogService::syncRadius();
        $mikrotik = SessionLogService::pollAllMikrotik();
        if ($radius || $mikrotik) {
            $this->info("{$radius} RADIUS and {$mikrotik} MikroTik session change(s) recorded");
        }
        return self::SUCCESS;
    }
}
