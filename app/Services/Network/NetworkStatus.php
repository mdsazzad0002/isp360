<?php

namespace App\Services\Network;

use App\Models\Connection;

// Live check of one connection against its router; stores the result on the connection
// (network_sync_status / network_sync_note / network_checked_at). Never changes the router.
class NetworkStatus
{
    public const LABELS = [
        'pending' => 'Sync pending',
        'synced' => 'Synced',
        'failed' => 'Sync failed',
        'not_managed' => 'Not managed',
        'mismatch' => 'Router mismatch',
    ];

    /** @return array{status: string, issues: array<int, string>} */
    public static function verify(Connection $connection): array
    {
        $driver = app(NetworkDriver::class);
        if ($reason = $driver->unmanagedReason($connection)) {
            $result = ['status' => 'not_managed', 'issues' => [$reason]];
        } else {
            try {
                $issues = $driver->verify($connection);
                $result = ['status' => $issues ? 'mismatch' : 'synced', 'issues' => $issues];
            } catch (\Throwable $e) {
                $result = ['status' => 'failed', 'issues' => [$e->getMessage()]];
            }
        }

        Connection::whereKey($connection->id)->update([
            'network_sync_status' => $result['status'],
            'network_sync_note' => $result['issues'] ? mb_substr(implode(' ', $result['issues']), 0, 500) : null,
            'network_checked_at' => now(),
        ] + ($result['status'] === 'failed' ? ['network_sync_error' => mb_substr($result['issues'][0], 0, 500)] : [])
          + ($result['status'] === 'synced' ? ['network_sync_error' => null] : []));
        return $result;
    }
}
