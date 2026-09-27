<?php

namespace App\Http\Controllers\Isp;

use App\Services\Isp\AuditLogger;
use App\Services\Isp\SessionLogService;
use Illuminate\Http\Request;

// Session log search and export for lawful requests ("who had IP x at time t"). Every search
// result export is audited with its reason.
class SessionLogController extends IspController
{
    private const RULES = [
        'ip' => 'nullable|ip',
        'port' => 'nullable|integer|min:1|max:65535',
        'username' => 'nullable|max:64',
        'customer_id' => 'nullable|integer',
        'mac' => 'nullable|max:50',
        'from' => 'nullable|date',
        'to' => 'nullable|date|after_or_equal:from',
    ];

    public function create()
    {
        return $this->page('sessionLog', 'Isp/SessionLog', [
            'retention_days' => (int) (company()?->log_retention_days ?? 365),
        ]);
    }

    public function index(Request $request)
    {
        if ($r = $this->deny('sessionLog')) return $r;
        if ($r = $this->validateOrFail($request->all(), self::RULES)) return $r;
        return response()->json(SessionLogService::search($request->only(array_keys(self::RULES)), $this->branchId)
            ->paginate(min(100, (int) ($request->per_page ?: 50))));
    }

    public function export(Request $request)
    {
        if ($r = $this->deny('sessionLog')) return $r;
        if ($r = $this->validateOrFail($request->all(), self::RULES + ['reason' => 'required|max:255'])) return $r;
        $filters = $request->only(array_keys(self::RULES));
        if (! array_filter($filters)) {
            return send_error('Narrow the export: give an IP, user, customer, MAC or time window.', null, 422);
        }
        $query = SessionLogService::search($filters, $this->branchId);
        AuditLogger::log('session_log.exported', null, null, $filters + ['rows' => (clone $query)->count()], $request->reason, $this->branchId);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, SessionLogService::CSV_COLUMNS);
            $query->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, SessionLogService::csvRow($row));
                }
            });
            fclose($out);
        }, 'session-log-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }
}
