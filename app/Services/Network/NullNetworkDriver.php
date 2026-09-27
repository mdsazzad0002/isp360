<?php

namespace App\Services\Network;

use App\Models\Connection;
use App\Models\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

// No router integration: staff enable/disable users on the router by hand. Swap in a
// MikroTik/RADIUS driver via ISP_NETWORK_DRIVER.
class NullNetworkDriver implements NetworkDriver
{
    public function unmanagedReason(Connection $connection): ?string
    {
        return 'No network driver configured (manual mode): change the router by hand.';
    }

    public function sync(Connection $connection): void
    {
        Log::channel('single')->info('[network:null] connection state', [
            'connection' => $connection->code,
            'status' => $connection->status,
            'pppoe_username' => $connection->pppoe_username,
        ]);
    }

    public function verify(Connection $connection): array
    {
        return [];
    }

    public function syncBlocks(Router $router, Collection $blocks): array
    {
        Log::channel('single')->info('[network:null] blocks', ['router' => $router->name, 'count' => $blocks->count()]);
        return [];
    }
}
