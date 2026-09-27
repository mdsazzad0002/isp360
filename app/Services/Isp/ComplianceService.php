<?php

namespace App\Services\Isp;

use App\Models\Connection;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\CustomerDocument;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\LegalDocument;
use App\Models\SessionLog;
use App\Support\CountryPack;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

// Customer data duties (roadmap 2.6): KYC documents, terms / privacy acceptance, marketing consent,
// a copy of a customer's data, and erasure that pseudonymises the person while every money row
// (invoices, payments, ledger) stays intact for the accounts and the auditors.
class ComplianceService
{
    public const DISK = 'local';
    public const OTHER_DOCUMENTS = ['contract' => 'Signed contract', 'photo' => 'Photo', 'address_proof' => 'Proof of address', 'other' => 'Other'];

    // ID types of the company's country (country pack) plus the other document kinds.
    public static function documentTypes(): array
    {
        return CountryPack::current()['id_types'] + self::OTHER_DOCUMENTS;
    }

    public static function addDocument(Customer $customer, string $type, ?string $number, ?UploadedFile $file, ?string $note = null): CustomerDocument
    {
        if (! array_key_exists($type, self::documentTypes())) {
            throw new RuntimeException('Unknown document type.');
        }
        $path = $file?->store("kyc/{$customer->branch_id}/{$customer->id}", self::DISK);
        $doc = CustomerDocument::create([
            'customer_id' => $customer->id,
            'type' => $type,
            'number' => $number,
            'file_path' => $path,
            'original_name' => $file ? mb_substr($file->getClientOriginalName(), 0, 255) : null,
            'status' => 'pending',
            'note' => $note,
            'branch_id' => $customer->branch_id,
            'created_by' => Auth::guard('web')->id(),
        ]);
        AuditLogger::log('kyc.document_added', $customer, null, ['document' => $doc->id, 'type' => $type], null, $customer->branch_id);
        return $doc;
    }

    public static function review(CustomerDocument $doc, string $status, ?string $note = null): CustomerDocument
    {
        if (! in_array($status, ['verified', 'rejected'], true)) {
            throw new RuntimeException('A document is verified or rejected.');
        }
        $doc->update(['status' => $status, 'note' => $note ?? $doc->note, 'verified_by' => Auth::guard('web')->id(), 'verified_at' => now()]);
        AuditLogger::log("kyc.document_{$status}", Customer::withTrashed()->find($doc->customer_id), null, ['document' => $doc->id, 'type' => $doc->type], $note, $doc->branch_id);
        return $doc;
    }

    // KYC is done once one identity document (a country pack ID type) is verified.
    public static function kycVerified(int $customerId): bool
    {
        return CustomerDocument::where('customer_id', $customerId)->where('status', 'verified')
            ->whereIn('type', array_keys(CountryPack::current()['id_types']))->exists();
    }

    public static function assertCanActivate(Connection $connection): void
    {
        if (IspSettings::get($connection->branch_id, 'kyc_required') && ! self::kycVerified($connection->customer_id)) {
            throw new RuntimeException("Verify the customer's identity document first (Customer → Compliance): the law here requires KYC before service starts.");
        }
    }

    // --- terms / privacy -------------------------------------------------------------------

    public static function current(string $type): ?LegalDocument
    {
        return LegalDocument::where('type', $type)->orderByDesc('version')->first();
    }

    // A new version; customers are asked to accept it at their next portal visit.
    public static function publish(string $type, string $body): LegalDocument
    {
        if (! array_key_exists($type, LegalDocument::TYPES)) {
            throw new RuntimeException('Unknown legal document.');
        }
        $doc = LegalDocument::create([
            'type' => $type,
            'version' => (int) LegalDocument::where('type', $type)->max('version') + 1,
            'body' => $body,
            'published_at' => now(),
            'created_by' => Auth::guard('web')->id(),
        ]);
        AuditLogger::log('legal.published', $doc, null, ['type' => $type, 'version' => $doc->version]);
        return $doc;
    }

    public static function record(Customer $customer, string $type, bool $granted, string $source, ?int $version = null): CustomerConsent
    {
        $consent = CustomerConsent::create([
            'customer_id' => $customer->id,
            'type' => $type,
            'version' => $version,
            'granted' => $granted,
            'source' => $source,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : mb_substr((string) request()->userAgent(), 0, 255),
            'recorded_by' => $source === 'admin' ? Auth::guard('web')->id() : null,
            'created_at' => now(),
        ]);
        if ($type === 'marketing') {
            Customer::whereKey($customer->id)->update(['marketing_opt_out' => ! $granted]);
        }
        return $consent;
    }

    // Terms / privacy versions the customer still has to accept.
    public static function pendingFor(Customer $customer): array
    {
        $pending = [];
        foreach (array_keys(LegalDocument::TYPES) as $type) {
            $doc = self::current($type);
            if ($doc && ! CustomerConsent::where('customer_id', $customer->id)->where('type', $type)->where('version', $doc->version)->where('granted', true)->exists()) {
                $pending[] = $doc->only(['id', 'type', 'version', 'body', 'published_at']) + ['title' => LegalDocument::TYPES[$type]];
            }
        }
        return $pending;
    }

    public static function acceptAll(Customer $customer, string $source): int
    {
        $pending = self::pendingFor($customer);
        foreach ($pending as $doc) {
            self::record($customer, $doc['type'], true, $source, $doc['version']);
        }
        return count($pending);
    }

    // --- data rights -----------------------------------------------------------------------

    // Everything held about the customer, for a data access request.
    public static function export(Customer $customer): array
    {
        AuditLogger::log('customer.data_exported', $customer, null, null, null, $customer->branch_id);
        return [
            'exported_at' => now()->toDateTimeString(),
            'customer' => $customer->makeHidden(['password'])->toArray(),
            'connections' => Connection::where('customer_id', $customer->id)->get()->makeHidden(['pppoe_password'])->toArray(),
            'invoices' => Invoice::with('items')->where('customer_id', $customer->id)->get()->toArray(),
            'payments' => CustomerPayment::where('customer_id', $customer->id)->get()->toArray(),
            'documents' => CustomerDocument::where('customer_id', $customer->id)->get(['type', 'number', 'status', 'created_at', 'verified_at'])->toArray(),
            'consents' => CustomerConsent::where('customer_id', $customer->id)->get(['type', 'version', 'granted', 'source', 'ip_address', 'created_at'])->toArray(),
            'sessions' => SessionLog::where('customer_id', $customer->id)->orderByDesc('started_at')->limit(5000)
                ->get(['started_at', 'stopped_at', 'framed_ip', 'nat_ip', 'nat_port_start', 'nat_port_end', 'mac', 'download_bytes', 'upload_bytes'])->toArray(),
        ];
    }

    /**
     * Right to erasure: the person's details are replaced, KYC files deleted, logins removed.
     * Invoices, payments and the ledger stay (legal bookkeeping duty) under the pseudonym, and so
     * do session logs until their retention ends (lawful-intercept duty). Only for a customer with
     * no line in service and nothing owed either way.
     */
    public static function erase(Customer $customer, string $reason): Customer
    {
        return DB::transaction(function () use ($customer, $reason) {
            $customer = Customer::lockForUpdate()->findOrFail($customer->id);
            if ($customer->erased_at) {
                throw new RuntimeException('This customer is already erased.');
            }
            if (Connection::where('customer_id', $customer->id)->where('status', '!=', 'terminated')->exists()) {
                throw new RuntimeException('Terminate every connection of this customer first.');
            }
            $balance = LedgerService::balance($customer->id);
            if (abs($balance) >= 0.005 || DepositService::held($customer->id) > 0) {
                throw new RuntimeException('Settle the balance and any deposit first (due, advance credit or deposit must be zero).');
            }

            foreach (CustomerDocument::where('customer_id', $customer->id)->get() as $doc) {
                if ($doc->file_path) {
                    Storage::disk(self::DISK)->delete($doc->file_path);
                }
                $doc->delete();
            }
            $pseudonym = "Erased customer #{$customer->id}";
            $customer->forceFill([
                'name' => $pseudonym, 'owner' => null, 'phone' => null, 'email' => null, 'nid' => null, 'date_of_birth' => null,
                'address' => null, 'billing_address' => null, 'city' => null, 'state' => null, 'postcode' => null,
                'username' => null, 'password' => null, 'image' => null, 'notes' => null,
                'account_status' => 'inactive', 'marketing_opt_out' => true, 'erased_at' => now(),
            ])->save();
            // device identifiers on the connections are personal data too
            Connection::where('customer_id', $customer->id)->update(['pppoe_password' => null, 'mac_address' => null]);
            AuditLogger::log('customer.erased', $customer, null, ['customer' => $customer->id], $reason, $customer->branch_id);
            return $customer;
        });
    }
}
