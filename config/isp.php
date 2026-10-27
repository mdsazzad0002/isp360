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

    // Content-Security-Policy on HTML pages (App\Http\Middleware\SecurityHeaders):
    // enforce (default) | report (browser only reports violations) | off
    'csp' => env('ISP_CSP', 'enforce'),

    // isp:backup (database + public/uploads + storage/app) runs daily at `at` when enabled and keeps
    // the newest `keep` archives in storage/app/backups. Copy them off the server too.
    'backup' => [
        'enabled' => (bool) env('ISP_BACKUP_ENABLED', true),
        'at' => env('ISP_BACKUP_AT', '02:30'),
        'keep' => (int) env('ISP_BACKUP_KEEP', 14),
    ],

    // OLTs (vendors in config/olt_vendors.php): isp:olt-poll reads every active OLT every few minutes;
    // an ONU receiving less than weak_rx_dbm is listed as weak signal; ONU changes go over the CLI
    'olt' => [
        'poll_enabled' => (bool) env('ISP_OLT_POLL', true),
        'weak_rx_dbm' => (float) env('ISP_OLT_WEAK_RX_DBM', -27),
        'snmp_timeout' => (float) env('ISP_OLT_SNMP_TIMEOUT', 3),
        'cli_timeout' => (float) env('ISP_OLT_CLI_TIMEOUT', 15),
        // a PON port counts as down when all its ONUs (at least this many) drop at once
        'pon_down_min_onus' => (int) env('ISP_OLT_PON_DOWN_MIN_ONUS', 2),
    ],

    // waiting database updates (after a code update) are applied by the scheduler on their own, after a
    // backup; false = an admin applies them from Admin → System & Automation
    'auto_migrate' => (bool) env('ISP_AUTO_MIGRATE', true),

    // where scheduled checks (nightly ledger check) send an alert e-mail; the admin banner shows either way
    'alert_email' => env('ISP_ALERT_EMAIL'),
];
