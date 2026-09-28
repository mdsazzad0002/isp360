<?php

return [
    // Class implementing App\Services\Network\NetworkDriver. The MikroTik driver (default) works
    // per router: a router set to "RADIUS" is driven through FreeRADIUS (RadiusDriver, database
    // connection "radius"), any other over the MikroTik API. Nothing is pushed for a branch until
    // a default router is added under Network Setup > Routers. NullNetworkDriver = manual mode.
    'network_driver' => env('ISP_NETWORK_DRIVER', \App\Services\Network\MikroTikDriver::class),

    // How a router can be managed (routers.driver), each a class implementing NetworkDriver.
    // "radius" covers every vendor that authenticates users with RADIUS — MikroTik, Huawei, Cisco,
    // Juniper, VyOS / Linux accel-ppp, pfSense / OPNsense — with the per-vendor details in
    // config/nas_vendors.php. A direct device API (e.g. Juniper NETCONF, VyOS HTTP API) is a new
    // entry here with its own driver class.
    'router_drivers' => [
        'mikrotik' => ['label' => 'MikroTik (REST API)', 'class' => \App\Services\Network\MikroTikDriver::class],
        'radius' => ['label' => 'RADIUS (FreeRADIUS): any vendor', 'class' => \App\Services\Network\RadiusDriver::class],
    ],

    // Router pushes and SMS run on queues ("network", "sms", "default"). Pick one:
    //  - Redis + Horizon (QUEUE_CONNECTION=redis, run `php artisan horizon` under Supervisor/systemd):
    //    for any installation with its own server; dashboard at /horizon.
    //  - Database queue worked by the scheduler (QUEUE_CONNECTION=database, ISP_QUEUE_IN_SCHEDULER=true):
    //    for shared hosting with only the cron entry; jobs start within a minute.
    //  - QUEUE_CONNECTION=sync: everything runs inside the web request, as before (small installations).
    'queue_in_scheduler' => (bool) env('ISP_QUEUE_IN_SCHEDULER', false),

    // isp:backup (database + public/uploads + storage/app) runs daily at `at` when enabled and keeps
    // the newest `keep` archives in storage/app/backups. Copy them off the server too.
    'backup' => [
        'enabled' => (bool) env('ISP_BACKUP_ENABLED', true),
        'at' => env('ISP_BACKUP_AT', '02:30'),
        'keep' => (int) env('ISP_BACKUP_KEEP', 14),
    ],
];
