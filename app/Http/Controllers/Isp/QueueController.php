<?php

namespace App\Http\Controllers\Isp;

use App\Services\Isp\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Background jobs (router pushes, SMS): waiting per queue and failed jobs with retry/delete.
// Works for every queue driver; Redis installations also get the full Horizon dashboard.
class QueueController extends IspController
{
    public const QUEUES = ['network', 'sms', 'default'];

    public function create()
    {
        return $this->page('queueMonitor', 'Isp/Queue');
    }

    public function index()
    {
        if ($r = $this->deny('queueMonitor')) return $r;
        $driver = config('queue.default');
        $waiting = [];
        foreach (self::QUEUES as $queue) {
            try {
                $waiting[$queue] = $driver === 'sync' ? 0 : Queue::size($queue);
            } catch (\Throwable) {
                $waiting[$queue] = null; // queue backend unreachable
            }
        }
        $failed = DB::table('failed_jobs')->latest('failed_at')->limit(100)->get()->map(fn ($job) => [
            'uuid' => $job->uuid,
            'queue' => $job->queue,
            'job' => class_basename(json_decode($job->payload, true)['displayName'] ?? ''),
            'error' => mb_substr(strtok($job->exception, "\n"), 0, 300),
            'failed_at' => $job->failed_at,
        ]);

        return response()->json([
            'driver' => $driver,
            'in_scheduler' => (bool) config('isp.queue_in_scheduler'),
            'horizon' => $driver === 'redis',
            'waiting' => $waiting,
            'failed' => $failed,
            'failed_total' => DB::table('failed_jobs')->count(),
        ]);
    }

    // Puts failed jobs back on their queue: one (uuid) or all.
    public function retry(Request $request)
    {
        if ($r = $this->deny('queueMonitor')) return $r;
        Artisan::call('queue:retry', ['id' => [$request->uuid ?: 'all']]);
        AuditLogger::log('queue.retried', null, null, ['job' => $request->uuid ?: 'all'], null, $this->branchId);
        return $this->ok($request->uuid ? 'Job queued again' : 'All failed jobs queued again');
    }

    public function forget(Request $request)
    {
        if ($r = $this->deny('queueMonitor')) return $r;
        if ($request->uuid) {
            Artisan::call('queue:forget', ['id' => $request->uuid]);
        } else {
            Artisan::call('queue:flush');
        }
        AuditLogger::log('queue.forgotten', null, null, ['job' => $request->uuid ?: 'all'], null, $this->branchId);
        return $this->ok($request->uuid ? 'Failed job deleted' : 'All failed jobs deleted');
    }
}
