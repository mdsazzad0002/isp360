<?php

return [
    // Class implementing App\Services\Network\NetworkDriver. The MikroTik driver does nothing
    // for a branch until a default router is added under Network Setup > Routers.
    'network_driver' => env('ISP_NETWORK_DRIVER', \App\Services\Network\MikroTikDriver::class),
];
