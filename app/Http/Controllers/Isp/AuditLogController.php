<?php

namespace App\Http\Controllers\Isp;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends IspController
{
    public function create()
    {
        return $this->page('auditLog', 'Isp/AuditLog');
    }

    public function index(Request $request)
    {
        if ($r = $this->deny('auditLog')) return $r;
        $logs = AuditLog::with(['user', 'reseller'])
            ->where('branch_id', $this->branchId)
            ->when($request->action, fn ($q, $a) => $q->where('action', 'like', "{$a}%"))
            ->when($request->userId, fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->subject, fn ($q, $s) => $q->where('auditable_type', $s))
            ->when($request->subjectId, fn ($q, $id) => $q->where('auditable_id', $id))
            ->when(sqlDate($request->dateFrom), fn ($q, $d) => $q->where('created_at', '>=', $d . ' 00:00:00'))
            ->when(sqlDate($request->dateTo), fn ($q, $d) => $q->where('created_at', '<=', $d . ' 23:59:59'))
            ->latest('id')
            ->paginate(min(100, (int) ($request->per_page ?: 30)));
        return response()->json($logs);
    }
}
