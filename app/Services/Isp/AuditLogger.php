<?php

namespace App\Services\Isp;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// Who / what / when / old value / new value / reason / IP for every financial or
// sensitive change. A null user means the system (scheduler, queued job).
class AuditLogger
{
    public static function log(string $action, ?Model $subject = null, array $old = null, array $new = null, ?string $reason = null, $branchId = null): void
    {
        $user = Auth::guard('web')->user();
        $reseller = $user ? null : Auth::guard('reseller')->user();
        $isConsole = app()->runningInConsole();

        AuditLog::create([
            'user_id' => $user?->id ?? $reseller?->id,
            'user_type' => $user ? 'user' : ($reseller ? 'reseller' : 'system'),
            'action' => $action,
            'auditable_type' => $subject ? class_basename($subject) : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'reason' => $reason ? mb_substr($reason, 0, 255) : null,
            'ip_address' => $isConsole ? null : request()->ip(),
            'user_agent' => $isConsole ? 'console' : mb_substr((string) request()->userAgent(), 0, 255),
            'branch_id' => $branchId ?? $subject?->branch_id ?? optional(session('branch'))->id,
            'created_at' => now(),
        ]);
    }
}
