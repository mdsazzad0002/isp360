<?php

namespace App\Http\Controllers\Isp;

use App\Services\Isp\AuditLogger;
use App\Services\Isp\IspSettings;
use Illuminate\Http\Request;

class SettingController extends IspController
{
    public function create()
    {
        return $this->page('ispSettings', 'Isp/Settings');
    }

    public function show()
    {
        return response()->json(IspSettings::all($this->branchId));
    }

    public function update(Request $request)
    {
        if ($r = $this->deny('ispSettings')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'due_days' => 'required|integer|min:0|max:90',
            'grace_days' => 'required|integer|min:0|max:90',
            'invoice_generate_day' => 'required|integer|min:1|max:28',
            'billing_month' => 'required|in:current,previous',
            'first_month_billing' => 'required|in:prorate,full,next_month',
            'invoice_prefix' => 'required|alpha_num|max:8',
            'receipt_prefix' => 'required|alpha_num|max:8',
            'credit_note_prefix' => 'required|alpha_num|max:8',
            'debit_note_prefix' => 'required|alpha_num|max:8',
            'refund_prefix' => 'required|alpha_num|max:8',
            'connection_prefix' => 'required|alpha_num|max:8',
            'sms_tpl_invoice' => 'nullable|max:320',
            'sms_tpl_payment' => 'nullable|max:320',
            'sms_tpl_suspend' => 'nullable|max:320',
            'sms_tpl_reactivate' => 'nullable|max:320',
        ])) return $r;

        $old = IspSettings::all($this->branchId);
        IspSettings::save($this->branchId, $request->only(array_keys(IspSettings::DEFAULTS)));
        $new = IspSettings::all($this->branchId);
        $changed = array_keys(array_diff_assoc(array_map('strval', $new), array_map('strval', $old)));
        if ($changed) {
            AuditLogger::log('settings.updated', null, array_intersect_key($old, array_flip($changed)), array_intersect_key($new, array_flip($changed)), null, $this->branchId);
        }
        return $this->ok('Settings saved successfully');
    }
}
