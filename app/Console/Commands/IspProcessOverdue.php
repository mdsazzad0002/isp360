<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Isp\OverdueService;
use Illuminate\Console\Command;

class IspProcessOverdue extends Command
{
    protected $signature = 'isp:process-overdue {--branch= : Only this branch id}';

    protected $description = 'Mark overdue invoices and suspend connections whose expire date has passed';

    public function handle(): int
    {
        $branches = Branch::query()->when($this->option('branch'), fn ($q, $id) => $q->where('id', $id))->pluck('id');
        foreach ($branches as $branchId) {
            $overdue = OverdueService::markOverdue($branchId);
            $suspended = OverdueService::autoSuspend($branchId);
            if ($overdue || $suspended) {
                $this->info("Branch {$branchId}: {$overdue} invoices marked overdue, {$suspended} connections suspended");
            }
        }
        return self::SUCCESS;
    }
}
