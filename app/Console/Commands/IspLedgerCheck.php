<?php

namespace App\Console\Commands;

use App\Support\Money;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Services\Isp\CollectionService;
use App\Support\SystemAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Financial integrity check. For every customer with ISP activity:
//   ledger sum == cached ledger_balance == open invoice due - unallocated payment money
class IspLedgerCheck extends Command
{
    protected $signature = 'isp:ledger-check {--fix-cache : Rebuild customers.ledger_balance from the ledger}
        {--alert : Raise (or clear) the admin alert, for the nightly run}';

    protected $description = 'Verify ledger, invoices and payments agree for every customer';

    public function handle(): int
    {
        $problems = 0;
        $lines = [];
        $ids = LedgerEntry::distinct()->pluck('customer_id');
        foreach ($ids as $customerId) {
            $ledger = Money::round((float) LedgerEntry::where('customer_id', $customerId)->sum(DB::raw('debit - credit')));
            $cached = Money::round((float) Customer::withTrashed()->where('id', $customerId)->value('ledger_balance'));
            $invoiceDue = Money::round((float) Invoice::where('customer_id', $customerId)->whereIn('status', Invoice::OPEN_STATUSES)->sum('due'));
            $expected = Money::round($invoiceDue - CollectionService::advanceCredit($customerId));

            if (! Money::equals($ledger, $cached) || ! Money::equals($ledger, $expected)) {
                $problems++;
                $lines[] = "Customer {$customerId}: ledger {$ledger}, cached {$cached}, invoices-minus-advance {$expected}";
                $this->error(end($lines));
                if ($this->option('fix-cache')) {
                    Customer::withTrashed()->where('id', $customerId)->update(['ledger_balance' => $ledger]);
                }
            }
        }
        $problems ? $this->warn("{$problems} customer(s) out of balance") : $this->info("All {$ids->count()} customer ledgers balance.");
        if ($this->option('alert')) {
            $problems
                ? SystemAlerts::raise('ledger', "Ledger check: {$problems} customer(s) out of balance. Run `php artisan isp:ledger-check` on the server.", $lines)
                : SystemAlerts::clear('ledger');
        }
        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
