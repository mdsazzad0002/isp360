<?php

namespace App\Http\Controllers\Isp;

use App\Models\Customer;
use App\Models\CustomerDeposit;
use App\Services\Isp\DepositService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Security deposits on the customer profile: receive, refund, apply to dues.
class DepositController extends IspController
{
    private function bankRule(): array
    {
        return ['nullable', 'integer', Rule::exists('banks', 'id')->where('branch_id', $this->branchId)];
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('ispPayment')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'customer_id' => 'required|integer',
            'connection_id' => 'nullable|integer',
            'amount' => 'required|numeric|min:0.001',
            'method' => 'required|in:cash,bank,bkash,nagad,rocket,card,other',
            'bank_id' => $this->bankRule(),
            'received_date' => 'nullable|date',
            'notes' => 'nullable|max:255',
        ])) return $r;
        try {
            $customer = Customer::where('branch_id', $this->branchId)->findOrFail($request->customer_id);
            $deposit = DepositService::receive($customer, $request->all());
            return $this->ok("Deposit {$deposit->deposit_no} received", ['id' => $deposit->id]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function refund(Request $request)
    {
        if ($r = $this->deny('ispRefund')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'required|integer',
            'amount' => 'nullable|numeric|min:0.001',
            'method' => 'required|in:cash,bank,bkash,nagad,rocket,card,other',
            'bank_id' => $this->bankRule(),
            'reason' => 'required|max:255',
        ])) return $r;
        try {
            $deposit = DepositService::refund(CustomerDeposit::where('branch_id', $this->branchId)->findOrFail($request->id), $request->all());
            return $this->ok("Deposit {$deposit->deposit_no} refunded");
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function apply(Request $request)
    {
        if ($r = $this->deny('ispPayment')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'required|integer',
            'amount' => 'nullable|numeric|min:0.001',
            'reason' => 'nullable|max:255',
        ])) return $r;
        try {
            $deposit = DepositService::apply(CustomerDeposit::where('branch_id', $this->branchId)->findOrFail($request->id),
                $request->filled('amount') ? (float) $request->amount : null, $request->reason);
            return $this->ok("Deposit {$deposit->deposit_no} applied to the customer's dues");
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }
}
