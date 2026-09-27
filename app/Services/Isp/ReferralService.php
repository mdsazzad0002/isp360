<?php

namespace App\Services\Isp;

use App\Support\Money;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

// Referral commission: when a referred customer's first service bill is fully paid, the
// existing customer who referred them gets the commission from the settings (fixed amount or a
// percent of that bill) as wallet credit. It is recorded as a customer payment with method
// 'referral' — advance credit that pays the referrer's next bills — once per new customer.
// It is not cash, so it has no cash-book entry.
class ReferralService
{
    public static function rewardIfDue(Invoice $invoice): void
    {
        if (! $invoice->service_months || $invoice->status !== 'paid') {
            return;
        }
        $settings = IspSettings::all($invoice->branch_id);
        if (! $settings['referral_enabled']) {
            return;
        }
        $customer = Customer::find($invoice->customer_id);
        if (! $customer?->referred_by_id || DB::table('referral_rewards')->where('referred_id', $customer->id)->exists()) {
            return;
        }
        $referrer = Customer::where('branch_id', $customer->branch_id)->find($customer->referred_by_id);
        if (! $referrer) {
            return;
        }

        $value = (float) $settings['referral_commission'];
        $amount = Money::round($settings['referral_commission_type'] === 'percent' ? ((float) $invoice->total - (float) $invoice->tax_total) * $value / 100 : $value);
        if ($amount <= 0) {
            return;
        }

        $payment = CollectionService::receive($referrer, [
            'amount' => $amount,
            'method' => 'referral',
            'source' => 'referral',
            'reference' => "Referral {$customer->code}",
            'notes' => "Referral commission for {$customer->name} ({$customer->code}), bill {$invoice->invoice_no}",
        ]);
        DB::table('referral_rewards')->insert([
            'referrer_id' => $referrer->id,
            'referred_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'customer_payment_id' => $payment->id,
            'branch_id' => $customer->branch_id,
            'created_at' => now(),
        ]);
        AuditLogger::log('referral.rewarded', $payment, null, ['referrer' => $referrer->code, 'referred' => $customer->code, 'amount' => $amount], null, $customer->branch_id);
    }
}
