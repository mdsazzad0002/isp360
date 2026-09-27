<?php

namespace App\Services\Isp;

use App\Support\Money;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Package;
use Illuminate\Support\Carbon;

// Day-wise adjustment when a connection changes package in the middle of its paid time.
// The paid time (expire_at) stays as it is. The whole days still left are revalued:
//   credit = what those days cost on the old bills (bill total / its paid days × days left)
//   cost   = the same days on the new package (new price / days in its cycle × days left)
// cost > credit -> an adjustment bill for the difference (customer due);
// credit > cost -> the difference goes to the customer's advance credit (method 'adjustment').
// Bonus days are free, so they are neither credited nor charged. The day in progress counts
// as used. Unpaid service bills (their time hasn't started) are voided and billed again on
// the new package.
// Credit, cost and difference are net of tax. The adjustment bill adds the new package's tax on
// the difference; a credit gives back the tax on it too (difference_gross is what moves).
class PackageChangeService
{
    public static function quote(Connection $connection, Package $new): array
    {
        $connection->loadMissing('package');
        $now = now();
        [$days, $credit, $companyCredit, $lines] = self::unusedDays($connection, $now);

        $newRates = TaxService::forPackage($new);
        $newCharge = TaxService::net(max(0, Money::round((float) $new->price - (float) $connection->discount)), $newRates);
        $cycleDays = (int) round($now->copy()->startOfDay()->diffInDays($now->copy()->startOfDay()->addMonthsNoOverflow($new->cycleMonths())));
        $cost = $days ? Money::round($newCharge / $cycleDays * $days) : 0.0;
        $credit = Money::round($credit);
        $difference = Money::round($cost - $credit);
        $differenceTax = TaxService::lineTax(abs($difference), $newRates, false)['amount'];

        $rebill = Invoice::where('connection_id', $connection->id)->whereNotNull('service_months')
            ->whereIn('status', Invoice::OPEN_STATUSES)->whereNull('credit_at')->whereNull('period_start')
            ->get(['id', 'invoice_no', 'total', 'paid', 'due']);

        return [
            'old_package' => $connection->package?->name,
            'new_package' => $new->name,
            'old_charge' => TaxService::net($connection->monthlyCharge(), TaxService::forPackage($connection->package)),
            'new_charge' => $newCharge,
            'cycle_days' => $cycleDays,
            'days_left' => $days,
            'expire_at' => $connection->expire_at?->toDateTimeString(),
            'bills' => $lines,
            'credit' => $credit,
            'cost' => $cost,
            'difference' => $difference, // net; > 0 customer pays, < 0 customer gets credit
            'difference_tax' => $differenceTax,
            'difference_gross' => $difference < 0 ? -Money::round(-$difference + $differenceTax) : Money::round($difference + $differenceTax),
            'company_credit' => Money::round($companyCredit),
            'company_cost' => self::companyCost($new, $cycleDays, $days, $cost),
            'rebill' => $rebill->map->only(['id', 'invoice_no', 'total', 'paid', 'due'])->values()->all(),
        ];
    }

    /**
     * The whole paid days still left on the line and what they are worth (net of tax), valued at
     * what their bills charged: [days, credit, company's share of it, lines]. Used for a package
     * change and for the credit at termination.
     */
    public static function unusedDays(Connection $connection, ?Carbon $now = null): array
    {
        $connection->loadMissing('package');
        $now ??= now();
        $running = self::runningBills($connection, $now);
        $days = 0;
        $credit = 0.0;
        $companyCredit = 0.0; // reseller lines: the company's share of that credit
        $lines = [];
        $current = $connection->package;
        foreach ($running as $bill) {
            $left = $bill->days_left;
            if ((int) $bill->package_id === (int) $current?->id) {
                // bought on this package: value the days at what the bill actually charged
                $paidDays = max(1, $bill->paid_days);
                $total = (float) $bill->total - (float) $bill->tax_total; // net of tax
                $companyTotal = $bill->reseller_id && $bill->reseller_cost !== null ? (float) $bill->reseller_cost : $total;
            } else {
                // bought on an earlier package and already adjusted once: value at the current package
                $paidDays = max(1, (int) round($bill->period_start->diffInDays($bill->period_start->copy()->addMonthsNoOverflow($current->cycleMonths()))));
                $total = TaxService::net($connection->monthlyCharge(), TaxService::forPackage($current));
                $companyTotal = self::companyCost($current, $paidDays, $paidDays, $total);
            }
            $value = Money::round($total / $paidDays * $left);
            $days += $left;
            $credit += $value;
            $companyCredit += Money::round($companyTotal / $paidDays * $left);
            $lines[] = ['invoice_no' => $bill->invoice_no, 'total' => $total, 'paid_days' => $paidDays, 'days_left' => $left, 'value' => $value];
        }

        return [$days, Money::round($credit), Money::round($companyCredit), $lines];
    }

    // Termination: the unused paid days go back to the customer's balance (advance credit), with
    // their tax. Returns the receipt, or null when nothing is left.
    public static function creditUnused(Connection $connection, ?string $reason = null): ?CustomerPayment
    {
        [$days, $credit] = self::unusedDays($connection);
        if ($credit < Money::unit()) {
            return null;
        }
        $tax = TaxService::lineTax($credit, TaxService::forPackage($connection->package), false)['amount'];
        $payment = CollectionService::receive(Customer::withTrashed()->findOrFail($connection->customer_id), [
            'amount' => Money::round($credit + $tax),
            'method' => 'adjustment',
            'reference' => "Termination {$connection->code}",
            'notes' => "Termination of {$connection->code}: {$days} unused paid day(s)" . ($reason ? ". {$reason}" : ''),
        ]);
        if ($tax > 0) {
            CustomerPayment::whereKey($payment->id)->update(['tax_amount' => $tax]); // for the tax report
        }
        AuditLogger::log('connection.unused_credited', $connection, null, ['days' => $days, 'credit' => $credit, 'tax' => $tax], $reason);
        return $payment;
    }

    // Applies the quote. Runs inside ConnectionService::changePackage's transaction, after the
    // connection has been moved to the new package. Returns a short summary for the user.
    public static function settle(Connection $connection, array $quote, ?string $reason): string
    {
        $notes = [];
        $customer = Customer::withTrashed()->findOrFail($connection->customer_id);
        $diff = $quote['difference'];
        $label = "Package change {$quote['old_package']} → {$quote['new_package']}, {$quote['days_left']} day(s) left";

        foreach ($quote['rebill'] as $bill) {
            BillingService::void(Invoice::findOrFail($bill['id']), "Package changed to {$quote['new_package']}; billed again on the new package");
        }
        if ($quote['rebill']) {
            BillingService::billNow($connection->fresh());
            $notes[] = count($quote['rebill']) . ' unpaid bill(s) re-issued on the new package';
        }

        if ($diff >= Money::unit()) {
            $data = ['connection_id' => $connection->id, 'invoice_date' => now(), 'due_date' => now(), 'notes' => $reason];
            $invoice = BillingService::createManual($customer, $data, [[
                'package_id' => $connection->package_id,
                'description' => "{$label}: new {$quote['cost']} − unused {$quote['credit']}",
                'unit_price' => TaxService::priceFromNet($diff, TaxService::forPackage($connection->package)), // taxed like the package
                'quantity' => 1,
            ]]);
            // Reseller line: the company's share of the extra, so the reseller keeps only their margin.
            if ($connection->package->reseller_id) {
                $share = min($diff, max(0, Money::round($quote['company_cost'] - $quote['company_credit'])));
                Invoice::whereKey($invoice->id)->update(['reseller_id' => $connection->package->reseller_id, 'reseller_cost' => $share]);
            }
            $notes[] = Money::format($invoice->total) . " added as due ({$invoice->invoice_no})";
        } elseif ($diff <= -Money::unit()) {
            $payment = CollectionService::receive($customer, [
                'amount' => -$quote['difference_gross'], // the unused days' tax comes back too
                'method' => 'adjustment',
                'reference' => "Package change {$connection->code}",
                'notes' => "{$label}: unused {$quote['credit']} − new {$quote['cost']}",
            ]);
            if ($quote['difference_tax'] > 0) {
                CustomerPayment::whereKey($payment->id)->update(['tax_amount' => $quote['difference_tax']]); // for the tax report
            }
            $notes[] = Money::format(-$quote['difference_gross']) . " credited to the customer's balance ({$payment->receipt_no})";
        }

        AuditLogger::log('connection.package_adjusted', $connection, null, [
            'days_left' => $quote['days_left'], 'credit' => $quote['credit'], 'cost' => $quote['cost'], 'difference' => $diff,
        ], $reason);
        return $notes ? implode('; ', $notes) . '.' : 'No paid days left to adjust; the new price applies from the next bill.';
    }

    // Paid (or started-on-due) service bills whose time hasn't run out. Whole days only:
    // the day in progress counts as used. The first window's bonus days are free.
    private static function runningBills(Connection $connection, Carbon $now)
    {
        $firstStart = Invoice::where('connection_id', $connection->id)->whereNotNull('service_months')
            ->whereNotIn('status', ['draft', 'void', 'cancelled'])->whereNotNull('period_start')->min('period_start');

        return Invoice::where('connection_id', $connection->id)->whereNotNull('service_months')
            ->whereNotIn('status', ['draft', 'void', 'cancelled'])
            ->whereNotNull('period_start')->where('period_end', '>', $now)
            ->orderBy('period_start')
            ->addSelect(['package_id' => \App\Models\InvoiceItem::select('package_id')->whereColumn('invoice_id', 'invoices.id')->whereNotNull('package_id')->limit(1)])
            ->get(['id', 'invoice_no', 'total', 'tax_total', 'period_start', 'period_end', 'reseller_id', 'reseller_cost'])
            ->map(function ($bill) use ($now, $connection, $firstStart) {
                $bonus = $firstStart && $bill->period_start->equalTo(Carbon::parse($firstStart)) ? (int) $connection->bonus_days : 0;
                $bonusFrom = $bill->period_end->copy()->subDays($bonus); // bonus days sit at the end of the window
                $bill->paid_days = (int) floor($bill->period_start->diffInSeconds($bonusFrom) / 86400);
                $from = $bill->period_start->gt($now) ? $bill->period_start : $now;
                $bill->days_left = max(0, min($bill->paid_days, (int) floor($from->diffInSeconds($bonusFrom, false) / 86400)));
                return $bill;
            })
            ->filter(fn ($bill) => $bill->days_left > 0)
            ->values();
    }

    private static function companyCost(Package $new, int $cycleDays, int $days, float $cost): float
    {
        if (! $new->reseller_id) {
            return $cost;
        }
        $base = $new->base_price ?? ($new->base_package_id ? $new->basePackage?->price : null);
        return $base === null || ! $days ? $cost : Money::round(TaxService::net((float) $base, TaxService::forPackage($new)) / $cycleDays * $days);
    }
}
