<?php

return [
    // Class implementing App\Services\Network\NetworkDriver. The MikroTik driver does nothing
    // for a branch until a default router is added under Network Setup > Routers.
    'network_driver' => env('ISP_NETWORK_DRIVER', \App\Services\Network\MikroTikDriver::class),

    // Router pushes and SMS run on queues ("network", "sms", "default"). Pick one:
    //  - Redis + Horizon (QUEUE_CONNECTION=redis, run `php artisan horizon` under Supervisor/systemd):
    //    for any installation with its own server; dashboard at /horizon.
    //  - Database queue worked by the scheduler (QUEUE_CONNECTION=database, ISP_QUEUE_IN_SCHEDULER=true):
    //    for shared hosting with only the cron entry; jobs start within a minute.
    //  - QUEUE_CONNECTION=sync: everything runs inside the web request, as before (small installations).
    'queue_in_scheduler' => (bool) env('ISP_QUEUE_IN_SCHEDULER', false),
];
