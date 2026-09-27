<?php

namespace App\Services\Network;

use App\Models\Connection;
use App\Models\Router;
use Illuminate\Support\Collection;

// Picks the driver per router, so one ISP can run MikroTik routers over their API next to NAS
// devices that authenticate with RADIUS: the connection's router (or the branch default) says
// which one (routers.driver). Bound as the NetworkDriver when ISP_NETWORK_DRIVER is MikroTik
// or this class (see AppServiceProvider).
class RouterDriver implements NetworkDriver
{
    public function __construct(private MikroTikDriver $mikrotik, private RadiusDriver $radius)
    {
    }

    public function for(?Router $router): NetworkDriver
    {
        return $router?->isRadius() ? $this->radius : $this->mikrotik;
    }

    private function forConnection(Connection $connection): NetworkDriver
    {
        // an assigned router decides even when it is inactive (the driver then reports that)
        $router = $connection->router_id ? Router::find($connection->router_id) : Router::forConnection($connection);
        return $this->for($router);
    }

    public function unmanagedReason(Connection $connection): ?string
    {
        return $this->forConnection($connection)->unmanagedReason($connection);
    }

    public function sync(Connection $connection): void
    {
        $this->forConnection($connection)->sync($connection);
    }

    public function verify(Connection $connection): array
    {
        return $this->forConnection($connection)->verify($connection);
    }

    public function syncBlocks(Router $router, Collection $blocks): array
    {
        return $this->for($router)->syncBlocks($router, $blocks);
    }
}
