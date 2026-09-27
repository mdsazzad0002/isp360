<?php

namespace App\Http\Controllers\Isp;

use App\Models\Region;
use App\Services\Isp\CompanyReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// Owner dashboard (roadmap 3.1) and regional view (3.2): every branch the user may see, side by side.
class CompanyDashboardController extends IspController
{
    public function create()
    {
        return $this->page('companyDashboard', 'Isp/CompanyDashboard');
    }

    public function index(Request $request)
    {
        if ($r = $this->deny('companyDashboard')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'region_id' => 'nullable|integer'])) return $r;
        $from = $request->from ? Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->to ? Carbon::parse($request->to) : now();
        $allowed = auth()->user()->allowedBranchIds();

        $report = CompanyReport::build($from, $to, $allowed, $request->integer('region_id') ?: null);
        $report['region_options'] = Region::when($allowed !== null, fn ($q) => $q->whereHas('branches', fn ($b) => $b->whereIn('id', $allowed ?: [0])))
            ->orderBy('name')->get(['id', 'name']);
        $report['scope'] = auth()->user()->region?->name ?? ($allowed === null ? 'All branches' : 'Assigned branches');
        return response()->json($report);
    }

    public function export(Request $request)
    {
        if ($r = $this->deny('companyDashboard')) return $r;
        $report = CompanyReport::build(
            $request->from ? Carbon::parse($request->from) : now()->startOfMonth(),
            $request->to ? Carbon::parse($request->to) : now(),
            auth()->user()->allowedBranchIds(),
            $request->integer('region_id') ?: null,
        );
        $cols = ['region', 'branch', 'subscribers', 'suspended', 'new', 'churned', 'churn_pct', 'arpu', 'billed', 'tax', 'revenue', 'collection', 'collection_pct',
            'other_income', 'expense', 'bandwidth_cost', 'profit', 'due', 'overdue', 'advance'];
        return response()->streamDownload(function () use ($report, $cols) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $cols);
            foreach ($report['branches'] as $row) {
                fputcsv($out, array_map(fn ($c) => $row[$c] ?? '', $cols));
            }
            fputcsv($out, array_map(fn ($c) => $c === 'branch' ? 'TOTAL' : ($report['total'][$c] ?? ''), $cols));
            fclose($out);
        }, "company-{$report['from']}-{$report['to']}.csv", ['Content-Type' => 'text/csv']);
    }
}
