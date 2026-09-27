<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Isp\BillingService;
use App\Services\Isp\IspSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class IspGenerateInvoices extends Command
{
    protected $signature = 'isp:generate-invoices {--branch= : Only this branch id} {--date= : Run as if today were this date (Y-m-d)} {--force : Ignore the auto_invoice / generate-day settings}';

    protected $description = 'Generate period invoices for active connections (safe to re-run)';

    public function handle(): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $branches = Branch::query()->when($this->option('branch'), fn ($q, $id) => $q->where('id', $id))->pluck('id');

        foreach ($branches as $branchId) {
            $settings = IspSettings::all($branchId);
            if (! $this->option('force') && (! $settings['auto_invoice'] || $date->day !== (int) $settings['invoice_generate_day'])) {
                continue;
            }
            $stats = BillingService::generateForBranch($branchId, $date);
            $this->info("Branch {$branchId}: {$stats['created']} created, {$stats['skipped']} skipped, {$stats['failed']} failed");
            foreach ($stats['errors'] as $error) {
                $this->warn('  ' . $error);
            }
        }
        return self::SUCCESS;
    }
}
