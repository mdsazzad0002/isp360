<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Services\Isp\CollectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Financial integrity check. For every customer with ISP activity:
//   ledger sum == cached ledger_balance == open invoice due - unallocated payment money
class IspLedgerCheck extends Command
{
    protected $signature = 'isp:ledger-check {--fix-cache : Rebuild customers.ledger_balance from the ledger}';

    protected $description = 'Verify ledger, invoices and payments agree for every customer';

    public function handle(): int
    {
        $problems = 0;
        $ids = LedgerEntry::distinct()->pluck('customer_id');
        foreach ($ids as $customerId) {
            $ledger = round((float) LedgerEntry::where('customer_id', $customerId)->sum(DB::raw('debit - credit')), 2);
            $cached = round((float) Customer::withTrashed()->where('id', $customerId)->value('ledger_balance'), 2);
            $invoiceDue = round((float) Invoice::where('customer_id', $customerId)->whereIn('status', Invoice::OPEN_STATUSES)->sum('due'), 2);
            $expected = round($invoiceDue - CollectionService::advanceCredit($customerId), 2);

            if (abs($ledger - $cached) > 0.009 || abs($ledger - $expected) > 0.009) {
                $problems++;
                $this->error("Customer {$customerId}: ledger {$ledger}, cached {$cached}, invoices-minus-advance {$expected}");
                if ($this->option('fix-cache')) {
                    Customer::withTrashed()->where('id', $customerId)->update(['ledger_balance' => $ledger]);
                }
            }
        }
        $problems ? $this->warn("{$problems} customer(s) out of balance") : $this->info("All {$ids->count()} customer ledgers balance.");
        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
