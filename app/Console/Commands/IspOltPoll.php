<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\Network\OltService;
use Illuminate\Console\Command;

// Reads every active OLT over SNMP: ONU status, optical power, distance. Scheduled every 5 minutes.
class IspOltPoll extends Command
{
    protected $signature = 'isp:olt-poll {--olt= : only this OLT id}';

    protected $description = 'Read ONU status and optical power from the OLTs';

    public function handle(): int
    {
        if (! $this->option('olt')) {
            $this->info(OltService::pollAll() . ' OLT(s) polled');
            return self::SUCCESS;
        }
        $olt = Olt::findOrFail($this->option('olt'));
        try {
            $s = OltService::poll($olt);
            $this->info("{$olt->name}: {$s['found']} ONU(s), {$s['online']} online, {$s['weak']} weak, {$s['new']} new, {$s['missing']} gone");
            return self::SUCCESS;
        } catch (\Throwable $th) {
            $this->error("{$olt->name}: {$th->getMessage()}");
            return self::FAILURE;
        }
    }
}
