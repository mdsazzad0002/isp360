<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Isp\OverdueService;
use App\Services\Isp\PromiseService;
use Illuminate\Console\Command;

class IspProcessOverdue extends Command
{
    protected $signature = 'isp:process-overdue {--branch= : Only this branch id}';

    protected $description = 'Mark overdue invoices, send suspension notices, charge late fees and suspend connections whose paid time (and grace) has passed';

    public function handle(): int
    {
        $branches = Branch::query()->when($this->option('branch'), fn ($q, $id) => $q->where('id', $id))->pluck('id');
        foreach ($branches as $branchId) {
            $overdue = OverdueService::markOverdue($branchId);
            // promises first: a broken one lets the suspension below happen in the same run
            [$kept, $broken] = PromiseService::resolve($branchId);
            $notices = OverdueService::sendNotices($branchId) + OverdueService::sendReminders($branchId);
            $fees = OverdueService::applyLateFees($branchId);
            $suspended = OverdueService::autoSuspend($branchId);
            if ($overdue || $notices || $fees || $suspended || $kept || $broken) {
                $this->info("Branch {$branchId}: {$overdue} invoices marked overdue, {$notices} reminders / notices sent, {$fees} late fees, {$suspended} connections suspended, {$kept} promises kept, {$broken} broken");
            }
        }
        return self::SUCCESS;
    }
}
