<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Isp\BillingService;
use App\Services\Isp\IspSettings;
use Illuminate\Console\Command;

class IspGenerateInvoices extends Command
{
    protected $signature = 'isp:generate-invoices {--branch= : Only this branch id} {--force : Ignore the auto_invoice setting}';

    protected $description = 'Issue renewal invoices for connections whose paid time ends soon (safe to re-run)';

    public function handle(): int
    {
        $branches = Branch::query()->when($this->option('branch'), fn ($q, $id) => $q->where('id', $id))->pluck('id');
        foreach ($branches as $branchId) {
            if (! $this->option('force') && ! IspSettings::get($branchId, 'auto_invoice')) {
                continue;
            }
            $stats = BillingService::generateForBranch($branchId);
            if ($stats['created'] || $stats['failed']) {
                $this->info("Branch {$branchId}: {$stats['created']} created, {$stats['failed']} failed");
            }
            foreach ($stats['errors'] as $error) {
                $this->warn('  ' . $error);
            }
        }
        return self::SUCCESS;
    }
}
