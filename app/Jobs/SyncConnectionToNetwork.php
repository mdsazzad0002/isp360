<?php

namespace App\Jobs;

use App\Models\Connection;
use App\Services\Network\NetworkDriver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// A router problem never undoes a billing action: the result is recorded on the
// connection (network_synced_at / network_sync_error) and can be retried from the UI.
class SyncConnectionToNetwork implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 600];

    public function __construct(public int $connectionId)
    {
    }

    public function handle(NetworkDriver $driver): void
    {
        $connection = Connection::with('package')->find($this->connectionId);
        if (! $connection) {
            return;
        }
        try {
            if ($reason = $driver->unmanagedReason($connection)) {
                Connection::whereKey($connection->id)->update(['network_sync_status' => 'not_managed', 'network_sync_note' => $reason, 'network_sync_error' => null]);
                return;
            }
            $driver->sync($connection);
            Connection::whereKey($connection->id)->update([
                'network_synced_at' => now(), 'network_sync_error' => null, 'network_sync_status' => 'synced', 'network_sync_note' => null,
            ]);
        } catch (\Throwable $e) {
            Connection::whereKey($connection->id)->update(['network_sync_error' => mb_substr($e->getMessage(), 0, 500), 'network_sync_status' => 'failed']);
            Log::warning('Network sync failed', ['connection' => $connection->id, 'error' => $e->getMessage()]);
            if (config('queue.default') !== 'sync') {
                throw $e; // let a real queue worker retry with backoff
            }
        }
    }
}
