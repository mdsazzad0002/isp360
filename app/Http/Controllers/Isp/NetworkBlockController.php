<?php

namespace App\Http\Controllers\Isp;

use App\Models\NetworkBlock;
use App\Models\Package;
use App\Models\Router;
use App\Services\Isp\AuditLogger;
use App\Services\Network\NetworkBlockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Site / IP block list. Every change is pushed to the branch's routers at once; the result per
// router is returned (and kept on the router) so staff see whether it reached the network.
class NetworkBlockController extends IspController
{
    public function create()
    {
        return $this->page('networkBlock', 'Isp/NetworkBlock');
    }

    public function index()
    {
        return response()->json([
            'blocks' => NetworkBlock::with('package', 'createdBy')->where('branch_id', $this->branchId)->orderByDesc('id')->get(),
            'routers' => Router::where('branch_id', $this->branchId)->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'host', 'block_sync_status', 'block_sync_note', 'block_synced_at']),
        ]);
    }

    // Several sites / IPs at once, one per line (or comma separated).
    public function store(Request $request)
    {
        if ($r = $this->deny('networkBlock')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'values' => 'required|string|max:20000',
            'scope' => 'required|in:all,package',
            'package_id' => 'required_if:scope,package|nullable|integer',
            'note' => 'nullable|max:255',
        ])) return $r;
        try {
            $packageId = $request->scope === 'package' ? Package::where('branch_id', $this->branchId)->findOrFail($request->package_id)->id : null;
            $lines = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $request->values)))));
            if (count($lines) > 500) {
                return send_error('Add at most 500 entries at a time.', null, 422);
            }
            $parsed = [];
            foreach ($lines as $line) {
                [$type, $value] = NetworkBlockService::parse($line);
                $parsed[$value] = $type;
            }

            $added = 0;
            $skipped = [];
            DB::transaction(function () use ($parsed, $packageId, $request, &$added, &$skipped) {
                foreach ($parsed as $value => $type) {
                    $exists = NetworkBlock::where('branch_id', $this->branchId)->where('value', $value)
                        ->where('scope', $request->scope)->where('package_id', $packageId)->exists();
                    if ($exists) {
                        $skipped[] = $value;
                        continue;
                    }
                    $block = NetworkBlock::create([
                        'type' => $type, 'value' => $value, 'scope' => $request->scope, 'package_id' => $packageId,
                        'note' => $request->note, 'is_active' => true, 'branch_id' => $this->branchId, 'created_by' => $this->userId,
                    ]);
                    AuditLogger::log('network_block.created', $block, null, $block->only(['type', 'value', 'scope', 'package_id']), $request->note);
                    $added++;
                }
            });

            $sync = NetworkBlockService::syncBranch($this->branchId);
            $message = "{$added} blocked" . ($skipped ? ', ' . count($skipped) . ' already in the list (' . implode(', ', array_slice($skipped, 0, 5)) . ')' : '') . '. ' . $this->syncSummary($sync);
            return $this->ok($message, ['sync' => $sync]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function toggle(Request $request)
    {
        if ($r = $this->deny('networkBlock')) return $r;
        $block = NetworkBlock::where('branch_id', $this->branchId)->findOrFail($request->id);
        $block->update(['is_active' => ! $block->is_active, 'updated_by' => $this->userId]);
        AuditLogger::log('network_block.' . ($block->is_active ? 'enabled' : 'disabled'), $block, null, $block->only(['value', 'is_active']));
        $sync = NetworkBlockService::syncBranch($this->branchId);
        return $this->ok(($block->is_active ? "{$block->value} blocked. " : "{$block->value} unblocked. ") . $this->syncSummary($sync), ['sync' => $sync]);
    }

    public function destroy(Request $request)
    {
        if ($r = $this->deny('networkBlock')) return $r;
        $ids = (array) ($request->ids ?? [$request->id]);
        $blocks = NetworkBlock::where('branch_id', $this->branchId)->whereIn('id', $ids)->get();
        foreach ($blocks as $block) {
            AuditLogger::log('network_block.deleted', $block, $block->only(['type', 'value', 'scope', 'package_id']), null);
            $block->delete();
        }
        $sync = NetworkBlockService::syncBranch($this->branchId);
        return $this->ok($blocks->count() . ' removed from the block list. ' . $this->syncSummary($sync), ['sync' => $sync]);
    }

    public function sync()
    {
        if ($r = $this->deny('networkBlock')) return $r;
        $sync = NetworkBlockService::syncBranch($this->branchId);
        return $this->ok($this->syncSummary($sync), ['sync' => $sync]);
    }

    private function syncSummary(array $sync): string
    {
        if (! $sync) {
            return 'No active router: nothing was pushed.';
        }
        $failed = collect($sync)->where('status', 'failed');
        return $failed->isEmpty()
            ? 'Pushed to ' . count($sync) . ' router(s).'
            : 'Router push failed on ' . $failed->pluck('router')->implode(', ') . ' — see the router status and Sync again.';
    }
}
