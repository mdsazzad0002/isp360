<?php

namespace App\Http\Controllers\Isp;

use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\CustomerDocument;
use App\Models\LegalDocument;
use App\Services\Isp\ComplianceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// Customer profile → Compliance: KYC documents, consents, data export, erasure; settings: terms / privacy.
class ComplianceController extends IspController
{
    private function customer(Request $request): Customer
    {
        return Customer::where('branch_id', $this->branchId)->findOrFail($request->customer_id ?? $request->id);
    }

    public function data(Request $request)
    {
        if ($r = $this->deny('customer')) return $r;
        $customer = $this->customer($request);
        return response()->json([
            'types' => ComplianceService::documentTypes(),
            'documents' => CustomerDocument::with('verifiedBy')->where('customer_id', $customer->id)->latest('id')->get(),
            'kyc_verified' => ComplianceService::kycVerified($customer->id),
            'consents' => CustomerConsent::where('customer_id', $customer->id)->latest('id')->limit(50)->get(),
            'pending_legal' => array_map(fn ($d) => array_diff_key($d, ['body' => 1]), ComplianceService::pendingFor($customer)),
            'marketing_opt_out' => (bool) $customer->marketing_opt_out,
            'erased_at' => $customer->erased_at,
        ]);
    }

    public function upload(Request $request)
    {
        if ($r = $this->deny('customer')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'customer_id' => 'required|integer',
            'type' => 'required|max:40',
            'number' => 'nullable|max:100',
            'file' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,webp,pdf',
            'note' => 'nullable|max:255',
        ])) return $r;
        try {
            $doc = ComplianceService::addDocument($this->customer($request), $request->type, $request->number, $request->file('file'), $request->note);
            return $this->ok('Document saved', ['id' => $doc->id]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function review(Request $request)
    {
        if ($r = $this->deny('kycVerify')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required|integer', 'status' => 'required|in:verified,rejected', 'note' => 'nullable|max:255'])) return $r;
        $doc = CustomerDocument::where('branch_id', $this->branchId)->findOrFail($request->id);
        ComplianceService::review($doc, $request->status, $request->note);
        return $this->ok('Document ' . $request->status);
    }

    // The file, only to staff of the branch (never a public URL).
    public function file(Request $request)
    {
        if (! checkAccess('customer')) abort(403);
        $doc = CustomerDocument::where('branch_id', $this->branchId)->findOrFail($request->id);
        abort_unless($doc->file_path && Storage::disk(ComplianceService::DISK)->exists($doc->file_path), 404);
        return Storage::disk(ComplianceService::DISK)->response($doc->file_path, $doc->original_name);
    }

    public function marketing(Request $request)
    {
        if ($r = $this->deny('customer')) return $r;
        $customer = $this->customer($request);
        ComplianceService::record($customer, 'marketing', ! $request->boolean('opt_out'), 'admin');
        return $this->ok($request->boolean('opt_out') ? 'No marketing SMS for this customer' : 'Marketing SMS allowed');
    }

    public function export(Request $request)
    {
        if ($r = $this->deny('customerDataRights')) return $r;
        $customer = $this->customer($request);
        return response()->json(ComplianceService::export($customer), 200, [
            'Content-Disposition' => "attachment; filename=\"customer-{$customer->code}-data.json\"",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function erase(Request $request)
    {
        if ($r = $this->deny('customerDataRights')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required|integer', 'reason' => 'required|max:255'])) return $r;
        try {
            ComplianceService::erase($this->customer($request), $request->reason);
            return $this->ok('Personal data erased. Bills, payments and legally kept session logs stay under the pseudonym.');
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    // Settings: current terms / privacy and publishing a new version.
    public function legal()
    {
        return response()->json(collect(LegalDocument::TYPES)->map(fn ($title, $type) => [
            'type' => $type, 'title' => $title, 'current' => ComplianceService::current($type),
        ])->values());
    }

    public function publish(Request $request)
    {
        if ($r = $this->deny('ispSettings')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['type' => 'required|in:' . implode(',', array_keys(LegalDocument::TYPES)), 'body' => 'required|string|min:20|max:100000'])) return $r;
        $doc = ComplianceService::publish($request->type, $request->body);
        return $this->ok(LegalDocument::TYPES[$doc->type] . " version {$doc->version} published. Customers accept it at their next portal visit.");
    }
}
