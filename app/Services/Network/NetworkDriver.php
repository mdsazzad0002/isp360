<?php

namespace App\Services\Network;

use App\Models\Connection;
use App\Models\Router;
use Illuminate\Support\Collection;

// Pushes a connection's desired state (enabled/disabled, profile, credentials) to the
// network side — MikroTik, RADIUS, OLT... Billing never talks to devices directly; it
// dispatches SyncConnectionToNetwork, which calls the configured driver.
interface NetworkDriver
{
    // Why this connection is not pushed anywhere (no router, unmanaged type...), or null
    // when sync() will really push it. Lets the UI say "not managed" instead of "synced".
    public function unmanagedReason(Connection $connection): ?string;

    public function sync(Connection $connection): void;

    // Live comparison with the device: a list of differences, empty when it matches.
    public function verify(Connection $connection): array;

    // Makes the router's site / IP blocks match $blocks (the branch's active NetworkBlock rows).
    // Returns warnings (things it could not apply); throws when the router can't be updated.
    public function syncBlocks(Router $router, Collection $blocks): array;
}
