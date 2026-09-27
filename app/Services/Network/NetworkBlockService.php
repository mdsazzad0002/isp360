<?php

namespace App\Services\Network;

use App\Models\NetworkBlock;
use App\Models\Router;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// Site / IP blocking: cleans up what staff type in and pushes the branch's active blocks to
// every active router of the branch. A router problem never loses a block: the result is kept
// on the router (block_sync_status / block_sync_note) and "Sync now" retries.
class NetworkBlockService
{
    /**
     * Turns one typed line into [type, value]: a URL or host becomes its domain
     * ("https://www.Facebook.com/x" -> facebook.com), an IPv4 address or CIDR subnet stays.
     */
    public static function parse(string $input): array
    {
        $value = strtolower(trim($input));
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value); // scheme

        if (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})(?:/(\d{1,2}))?$#', $value, $m)) {
            if (! filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || (isset($m[2]) && (int) $m[2] > 32)) {
                throw new RuntimeException("{$input} is not a valid IPv4 address or subnet.");
            }
            if (isset($m[2]) && (int) $m[2] < 8) {
                throw new RuntimeException("{$input} is too wide to block (smallest allowed is /8).");
            }
            return ['ip', $value];
        }

        $value = preg_replace('#[/?\#].*$#', '', $value); // path, query
        if (preg_match('#^\d{1,3}(?:\.\d{1,3}){3}$#', $value)) {
            return self::parse($value); // a link to an IP address
        }
        $value = preg_replace('#^\*\.#', '', $value);     // *.example.com
        $value = preg_replace('#:\d+$#', '', $value);      // port
        $value = preg_replace('#^www\.#', '', $value); // www. is added on the router
        $value = rtrim($value, '.');
        if (! preg_match('/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $value)) {
            throw new RuntimeException("{$input} is not a valid domain, IP address or subnet.");
        }
        return ['domain', $value];
    }

    /** @return array<int, array{router:string, status:string, note:?string}> */
    public static function syncBranch(int $branchId): array
    {
        $driver = app(NetworkDriver::class);
        $blocks = NetworkBlock::with('package')->where('branch_id', $branchId)->where('is_active', true)->orderBy('id')->get();
        $results = [];
        foreach (Router::where('branch_id', $branchId)->where('is_active', true)->get() as $router) {
            try {
                $warnings = $driver->syncBlocks($router, $blocks);
                $note = $warnings ? mb_substr(implode(' ', $warnings), 0, 500) : null;
                $router->update(['block_sync_status' => $warnings ? 'warning' : 'synced', 'block_sync_note' => $note, 'block_synced_at' => now()]);
                $results[] = ['router' => $router->name, 'status' => $warnings ? 'warning' : 'synced', 'note' => $note];
            } catch (\Throwable $e) {
                $router->update(['block_sync_status' => 'failed', 'block_sync_note' => mb_substr($e->getMessage(), 0, 500), 'block_synced_at' => now()]);
                Log::warning('Block sync failed', ['router' => $router->id, 'error' => $e->getMessage()]);
                $results[] = ['router' => $router->name, 'status' => 'failed', 'note' => $e->getMessage()];
            }
        }
        return $results;
    }
}
