<?php

namespace App\Services\Network;

use App\Models\Olt;

// Reads an OLT. One driver per way of talking to OLTs (SNMP today); per-vendor differences are data in
// config/olt_vendors.php. A vendor entry may name its own 'driver' class (e.g. a CLI driver later).
interface OltDriver
{
    // One line for the OLT list: reachable, what it is, uptime. Throws when unreachable.
    public function test(Olt $olt): string;

    // Every ONU the OLT knows: list of ['pon_port', 'onu_id', 'serial', 'mac', 'name', 'status',
    // 'rx_power', 'tx_power', 'distance_m'] (missing values null).
    public function onus(Olt $olt): array;
}
