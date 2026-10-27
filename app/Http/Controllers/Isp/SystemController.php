<?php

namespace App\Http\Controllers\Isp;

use App\Services\Isp\AuditLogger;
use App\Support\SystemTasks;
use Illuminate\Http\Request;

// Admin → System & Automation: background tasks, their last runs and "Run now", database updates,
// the job queue, and whether the scheduler is alive.
class SystemController extends IspController
{
    public function create()
    {
        return $this->page('system', 'Isp/System');
    }

    public function index()
    {
        if ($r = $this->deny('system')) return $r;
        $beat = SystemTasks::lastHeartbeat();
        return response()->json([
            'tasks' => SystemTasks::overview(),
            'scheduler' => [
                'last_beat' => $beat,
                'running' => $beat && now()->diffInMinutes($beat, true) <= 3,
                'cron_line' => SystemTasks::cronLine(),
            ],
            'migrations' => ['pending' => SystemTasks::pendingMigrations(), 'auto' => (bool) config('isp.auto_migrate')],
            'queue' => SystemTasks::queue(),
            'php' => PHP_VERSION,
            'timezone' => config('app.timezone'),
        ]);
    }

    public function run(Request $request)
    {
        if ($r = $this->deny('system')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['command' => 'required|in:' . implode(',', array_keys(SystemTasks::TASKS))])) return $r;
        if ($request->command === 'isp:backup' && ! checkAccess('backup')) {
            return send_error('Backups need the Backups permission.', null, 403);
        }
        $result = SystemTasks::run($request->command);
        AuditLogger::log('system.task_run', null, null, ['command' => $request->command, 'ok' => $result['ok']]);
        return $result['ok']
            ? $this->ok(SystemTasks::TASKS[$request->command][0] . ' finished', ['output' => $result['output']])
            : send_error(SystemTasks::TASKS[$request->command][0] . ' failed', ['output' => $result['output']], 422);
    }
}
