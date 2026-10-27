<?php

namespace App\Support;

use App\Services\Isp\AuditLogger;
use App\Services\Isp\BackupService;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

// Admin → System & Automation: the background work the scheduler does, when each task last ran and
// how it ended, a "Run now" for each, database updates, and whether the scheduler itself is alive.
// Nothing here needs a terminal; the one thing outside the app is the cron line that starts the scheduler.
class SystemTasks
{
    private const HEARTBEAT = 'isp.scheduler_heartbeat';

    // command => [label, what it does, how often, extra arguments for "Run now"]
    public const TASKS = [
        'isp:generate-invoices' => ['Renewal invoices', 'Makes the next bill for every line whose billing date has come.', 'every minute', []],
        'isp:process-overdue' => ['Suspend unpaid lines', 'Turns off lines whose paid time ended (after grace days) and sends notices.', 'every minute', []],
        'isp:session-logs' => ['Session log', 'Records who had which IP when, from RADIUS accounting and MikroTik.', 'every 5 minutes', []],
        'isp:olt-poll' => ['OLT polling', 'Reads ONU status and optical power from the OLTs; raises outage alerts.', 'every 5 minutes', []],
        'isp:ledger-check' => ['Ledger check', 'Checks every customer\'s balance against the ledger; alerts on a mismatch.', 'daily 03:40', ['--alert' => true]],
        'isp:backup' => ['Full backup', 'Database + uploaded files, kept in storage/app/backups.', 'daily', []],
        'queue:work' => ['Background jobs', 'Router pushes and SMS waiting in the queue.', 'when jobs wait', ['--queue' => 'network,sms,default', '--stop-when-empty' => true, '--max-time' => 50, '--tries' => 3]],
        'migrate' => ['Database updates', 'Applies new database changes after an update (a backup is taken first).', 'when an update arrives', ['--force' => true]],
    ];

    private static array $running = [];

    // Records every run of a listed command started by artisan itself (the scheduler's subprocesses, a
    // console). Artisan::call from a web request fires no command events, so run() records its own.
    public static function listen(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $e) {
            if (! isset(self::TASKS[$e->command]) || $e->command === self::$calling || ! self::ready()) {
                return;
            }
            self::$running[$e->command] = self::start($e->command, 'schedule');
        });
        Event::listen(CommandFinished::class, function (CommandFinished $e) {
            if (! isset(self::$running[$e->command])) {
                return;
            }
            DB::table('system_task_runs')->where('id', self::$running[$e->command])->update(['finished_at' => now(), 'exit_code' => $e->exitCode]);
            unset(self::$running[$e->command]);
        });
    }

    private static ?string $calling = null; // the command run() is running, recorded by run() itself

    private static function ready(): bool
    {
        try {
            return Schema::hasTable('system_task_runs');
        } catch (\Throwable) {
            return false;
        }
    }

    private static function start(string $command, string $source): int
    {
        return DB::table('system_task_runs')->insertGetId(['command' => $command, 'source' => $source, 'started_at' => now(), 'user_id' => auth()->id()]);
    }

    // Runs one task now; returns ['ok' => bool, 'output' => tail of the output].
    public static function run(string $command, string $source = 'dashboard'): array
    {
        if (! isset(self::TASKS[$command])) {
            throw new \RuntimeException("Unknown task {$command}");
        }
        @set_time_limit(600);
        $started = now();
        $id = self::ready() ? self::start($command, $source) : null; // before the first migrate there is no table yet
        self::$calling = $command;
        try {
            if ($command === 'migrate' && self::pendingMigrations()) {
                BackupService::create('isp-backup-' . now()->format('Ymd-His') . '-before-update.tar.gz'); // a way back if an update goes wrong
            }
            $code = Artisan::call($command, self::TASKS[$command][3]);
            $output = trim(Artisan::output());
        } catch (\Throwable $th) {
            $code = 1;
            $output = $th->getMessage();
        } finally {
            self::$calling = null;
        }
        $output = mb_substr($output, -4000);
        $done = ['finished_at' => now(), 'exit_code' => $code, 'output' => $output];
        if ($id) {
            DB::table('system_task_runs')->where('id', $id)->update($done);
        } elseif (self::ready()) {
            DB::table('system_task_runs')->insert($done + ['command' => $command, 'source' => $source, 'started_at' => $started, 'user_id' => auth()->id()]);
        }
        if ($command === 'migrate') {
            Cache::forget('isp.pending_migrations');
        }
        return ['ok' => $code === 0, 'output' => $output];
    }

    // [command => label, what, schedule, last run row] for the page
    public static function overview(): array
    {
        $out = [];
        foreach (self::TASKS as $command => [$label, $what, $when]) {
            $last = DB::table('system_task_runs')->where('command', $command)->latest('id')->first();
            $lastOk = DB::table('system_task_runs')->where('command', $command)->where('exit_code', 0)->max('finished_at');
            $out[] = ['command' => $command, 'label' => $label, 'what' => $what, 'when' => $when, 'last' => $last, 'last_ok_at' => $lastOk];
        }
        return $out;
    }

    // ---- the scheduler's pulse ----

    public static function heartbeat(): void
    {
        Cache::forever(self::HEARTBEAT, now()->toDateTimeString());
    }

    public static function lastHeartbeat(): ?string
    {
        return Cache::get(self::HEARTBEAT);
    }

    // the line that starts the scheduler; the only thing set up outside the app
    public static function cronLine(): string
    {
        return '* * * * * cd ' . base_path() . ' && ' . (PHP_BINARY ?: 'php') . ' artisan schedule:run >> /dev/null 2>&1';
    }

    // ---- database updates ----

    public static function pendingMigrations(): array
    {
        return Cache::remember('isp.pending_migrations', 60, function () {
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return ['(database not set up)'];
            }
            $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
            return array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        });
    }

    // Scheduled: applies waiting database updates on its own (after a backup) unless ISP_AUTO_MIGRATE=false.
    public static function autoMigrate(): void
    {
        if (! config('isp.auto_migrate') || ! self::pendingMigrations()) {
            return;
        }
        $result = self::run('migrate', 'auto');
        AuditLogger::log($result['ok'] ? 'system.auto_migrated' : 'system.auto_migrate_failed', null, null, ['output' => mb_substr($result['output'], -1000)]);
        $result['ok'] ? SystemAlerts::clear('update') : SystemAlerts::raise('update', 'A database update failed to apply: open Admin → System & Automation.');
    }

    // ---- the job queue ----

    public static function queue(): array
    {
        $driver = config('queue.default');
        $waiting = 0;
        foreach (['network', 'sms', 'default'] as $name) {
            try {
                $waiting += Queue::size($name);
            } catch (\Throwable) {
            }
        }
        $oldest = $driver === 'database' && Schema::hasTable('jobs') ? DB::table('jobs')->whereNull('reserved_at')->min('available_at') : null;
        return [
            'driver' => $driver,
            'waiting' => $waiting,
            'oldest_wait_seconds' => $oldest ? max(0, time() - (int) $oldest) : null,
            'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'worked_by_scheduler' => (bool) config('isp.queue_in_scheduler'),
        ];
    }

    // Jobs have waited over 3 minutes on the database queue: nothing is working it.
    public static function queueStuck(): bool
    {
        try {
            return config('queue.default') === 'database'
                && DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<', time() - 180)->exists();
        } catch (\Throwable $th) {
            Log::warning('Queue check failed: ' . $th->getMessage());
            return false;
        }
    }

    // Shown to admins next to the stored alerts: computed, so they clear themselves.
    public static function liveAlerts(): array
    {
        $alerts = [];
        $beat = self::lastHeartbeat();
        if ($beat && now()->diffInMinutes($beat, true) > 10) {
            $alerts[] = ['key' => 'scheduler', 'message' => "Automatic tasks stopped at {$beat} (the scheduler is not running): billing, suspensions and polling wait. Open Admin → System & Automation.", 'since' => $beat];
        }
        if (! config('isp.auto_migrate') && self::pendingMigrations()) {
            $alerts[] = ['key' => 'migrations', 'message' => 'Database updates are waiting: Admin → System & Automation → Apply.', 'since' => null];
        }
        return $alerts;
    }

    public static function prune(int $days = 7): int
    {
        return DB::table('system_task_runs')->where('started_at', '<', now()->subDays($days))->delete();
    }
}
