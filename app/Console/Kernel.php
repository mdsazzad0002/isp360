<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // ISP prepaid billing, every minute so a line goes off right when its paid time ends.
        // Both are idempotent, so a missed or repeated run is harmless.
        $schedule->command('isp:generate-invoices')->everyMinute()->withoutOverlapping();
        $schedule->command('isp:process-overdue')->everyMinute()->withoutOverlapping();

        // Queues (see config/isp.php). Without a dedicated worker the scheduler works the database
        // queue every minute until it is empty.
        if (config('isp.queue_in_scheduler') && config('queue.default') !== 'sync') {
            $schedule->command('queue:work --queue=network,sms,default --stop-when-empty --max-time=55 --tries=3')
                ->everyMinute()->withoutOverlapping(5)->runInBackground();
        }
        if (config('queue.default') === 'redis') {
            $schedule->command('horizon:snapshot')->everyFiveMinutes();
        }
        $schedule->command('queue:prune-failed --hours=720')->daily();

        // lawful session log: who had which IP when (RADIUS accounting, MikroTik polling), then retention
        $schedule->command('isp:session-logs')->everyFiveMinutes()->withoutOverlapping(15);
        $schedule->command('isp:session-logs --prune')->dailyAt('03:10');

        // sign-in history older than 60 days
        $schedule->call(fn () => \App\Support\LoginSessions::prune())->dailyAt('03:20')->name('login-sessions-prune');

        // full backup: database + uploaded and private files
        if (config('isp.backup.enabled')) {
            $schedule->command('isp:backup')->dailyAt(config('isp.backup.at'))->withoutOverlapping(120);
        }
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
