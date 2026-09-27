<?php

namespace App\Services\Isp;

use App\Models\BillingNote;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// Invoices: generation from connections, manual invoices, draft -> issue, void,
// and credit/debit notes. Invoice amounts are snapshots — package edits never
// reach an existing invoice. Editing financial fields of an issued invoice is not
// possible; corrections go through notes or void + reissue.
class BillingService
{
    /**
     * Renewal invoices: every switched-on connection whose paid time ends within
     * renewal_invoice_days (or has already ended) and has no open service invoice gets one.
     * Idempotent — a connection never has two open service invoices, so dues don't pile up.
     *
     * @return array{created:int, skipped:int, failed:int, errors:array}
     */
    public static function generateForBranch(int $branchId, ?array $connectionIds = null): array
    {
        $settings = IspSettings::all($branchId);
        $horizon = now()->addDays($settings['renewal_invoice_days']);
        $stats = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        Connection::where('branch_id', $branchId)
            // A line cut off for expiry is still billed, so there is always an invoice to pay to get it back.
            ->where(fn ($q) => $q->where('status', 'active')
                ->orWhere(fn ($w) => $w->where('status', 'suspended')->whereIn('suspension_reason', OverdueService::SUSPEND_REASONS)))
            ->whereNotNull('activated_at')
            ->where(fn ($q) => $q->whereNull('expire_at')->orWhere('expire_at', '<=', $horizon))
            ->whereNotExists(fn ($q) => self::openServiceInvoice($q))
            ->when($connectionIds, fn ($q) => $q->whereIn('id', $connectionIds))
            ->orderBy('id')
            ->chunkById(200, function ($connections) use (&$stats) {
                foreach ($connections as $connection) {
                    try {
                        self::billNow($connection) ? $stats['created']++ : $stats['skipped']++;
                    } catch (\Throwable $e) {
                        $stats['failed']++;
                        $stats['errors'][] = "{$connection->code}: {$e->getMessage()}";
                        Log::error('ISP invoice generation failed', ['connection' => $connection->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        if ($stats['created'] || $stats['failed']) {
            AuditLogger::log('billing.generated', null, null, ['created' => $stats['created'], 'failed' => $stats['failed']], null, $branchId);
        }
        return $stats;
    }

    /**
     * One service invoice for the connection's package cycle, unless one is already open.
     * The customer's advance credit pays it at once; otherwise it stays due. Its internet time
     * starts when it is paid (ConnectionService::refreshExpiry), not when it is issued.
     */
    public static function billNow(Connection $connection, bool $extra = false): ?Invoice
    {
        $invoice = DB::transaction(function () use ($connection, $extra) {
            $connection = Connection::with('package', 'customer')->lockForUpdate()->find($connection->id);
            if (! $connection || ! $connection->package) {
                return null;
            }
            $open = Invoice::where('connection_id', $connection->id)->whereNotNull('service_months')
                ->whereIn('status', array_merge(Invoice::OPEN_STATUSES, ['draft']))->exists();
            if ($open && ! $extra) { // $extra: an advance cycle bought on top, paid right away
                return null;
            }

            $package = $connection->package;
            $months = $package->cycleMonths();
            $amount = $connection->monthlyCharge(); // package price is per billing cycle, minus discount
            $speed = $package->download_mbps ? " ({$package->download_mbps} Mbps)" : '';
            $length = $months === 1 ? '1 month' : "{$months} months";
            $today = now()->startOfDay();
            $dueDate = $connection->expire_at && $connection->expire_at->isFuture() ? $connection->expire_at->copy()->startOfDay() : $today;

            $invoice = self::createInvoiceRecord($connection->customer, $connection->branch_id, [
                'connection_id' => $connection->id,
                'service_months' => $months,
                'invoice_date' => $today,
                'due_date' => $dueDate,
                'source' => 'auto',
            ] + self::resellerCost($connection, $amount), [[
                'package_id' => $connection->package_id,
                'description' => "{$package->name}{$speed} — {$length} internet",
                'unit_price' => $amount,
                'quantity' => 1,
                'discount' => 0,
            ]], true);

            CollectionService::autoAllocate($connection->customer_id);
            return $invoice->fresh();
        });

        if ($invoice) {
            // Inside a larger transaction (connection create), notify only once it commits.
            DB::afterCommit(fn () => IspNotifier::send($invoice->branch_id, $invoice->customer, 'invoice', [
                'invoice' => $invoice->invoice_no,
                'amount' => number_format((float) $invoice->total, 2),
                'due_date' => $invoice->due_date->format('d-m-Y'),
            ]));
        }
        return $invoice;
    }

    /**
     * What paying 1..12 cycles for a connection would cost and until when it would run. Open
     * service bills count first; each further cycle is one more bill at the current price.
     */
    public static function payQuote(Connection $connection): array
    {
        $connection->loadMissing('package', 'customer');
        $open = self::openServiceInvoices($connection->id);
        $cycleMonths = $connection->package->cycleMonths();
        $charge = $connection->monthlyCharge();
        $advance = CollectionService::advanceCredit($connection->customer_id);
        $firstTime = ! Invoice::where('connection_id', $connection->id)->whereNotNull('service_months')->where('status', 'paid')->exists();
        // new time stacks on the time left, never starts before now or before activation
        $start = $connection->activated_at ? collect([now(), $connection->activated_at, $connection->expire_at])->filter()->max()->copy() : null;

        $options = [];
        for ($cycles = max(1, $open->count()); $cycles <= 12; $cycles++) {
            $extra = $cycles - $open->count();
            $months = (int) $open->sum('service_months') + $extra * $cycleMonths;
            $until = $start?->copy()->addMonthsNoOverflow($months);
            if ($until && $firstTime && $connection->bonus_days) {
                $until->addDays($connection->bonus_days);
            }
            $options[] = [
                'cycles' => $cycles,
                'months' => $months,
                'amount' => round(max(0, (float) $open->sum('due') + $extra * $charge - $advance), 2),
                'until' => $until?->toDateTimeString(),
            ];
        }
        return [
            'cycle_months' => $cycleMonths,
            'charge' => $charge,
            'advance' => $advance,
            'bonus_days' => $firstTime ? (int) $connection->bonus_days : 0,
            'open' => $open->map->only(['id', 'invoice_no', 'due', 'service_months', 'status'])->values(),
            'options' => $options,
        ];
    }

    /**
     * Pays $cycles of service for a connection in one payment: the open service bill(s) first,
     * then extra cycle bills created now. Pay first, service after: the time starts only when
     * the bills are fully paid. Returns the payment (null when advance credit covered it all).
     */
    public static function payConnection(Connection $connection, int $cycles, array $paymentData): ?\App\Models\CustomerPayment
    {
        return DB::transaction(function () use ($connection, $cycles, $paymentData) {
            $connection = Connection::with('package', 'customer')->lockForUpdate()->findOrFail($connection->id);
            if (in_array($connection->status, ['terminated', 'inactive'], true)) {
                throw new RuntimeException("A {$connection->status} connection can't be paid for.");
            }
            $open = self::openServiceInvoices($connection->id);
            if ($cycles < max(1, $open->count()) || $cycles > 12) {
                throw new RuntimeException('Choose between ' . max(1, $open->count()) . ' and 12 cycles.');
            }
            for ($i = $open->count(); $i < $cycles; $i++) {
                self::billNow($connection, true);
            }
            $targets = self::openServiceInvoices($connection->id);
            $amount = round((float) $targets->sum('due'), 2);
            if ($amount <= 0) {
                return null; // advance credit paid it
            }
            return CollectionService::receive($connection->customer, $paymentData + ['amount' => $amount, 'source' => 'admin'],
                $targets->mapWithKeys(fn ($inv) => [$inv->id => (float) $inv->due])->all(), false);
        });
    }

    private static function openServiceInvoices(int $connectionId)
    {
        return Invoice::where('connection_id', $connectionId)->whereNotNull('service_months')
            ->whereIn('status', Invoice::OPEN_STATUSES)->where('due', '>', 0)
            ->orderBy('id')->get(['id', 'invoice_no', 'due', 'service_months', 'status']);
    }

    private static function openServiceInvoice($query)
    {
        return $query->selectRaw(1)->from('invoices')
            ->whereColumn('invoices.connection_id', 'connections.id')
            ->whereNotNull('invoices.service_months')
            ->whereIn('invoices.status', array_merge(Invoice::OPEN_STATUSES, ['draft']));
    }

    // A reseller package invoice records the company's share: the company price the reseller
    // last accepted (base_price), so a company price change counts only once the reseller has
    // reviewed it. A reseller package with no base package has no known share: the whole
    // amount is the company's.
    private static function resellerCost(Connection $connection, float $amount): array
    {
        $package = $connection->package;
        if (! $package->reseller_id) {
            return [];
        }
        $basePrice = $package->base_price ?? ($package->base_package_id ? $package->basePackage?->price : null);
        $cost = $basePrice !== null ? round((float) $basePrice, 2) : $amount;
        return ['reseller_id' => $package->reseller_id, 'reseller_cost' => $cost];
    }

    /**
     * Manual invoice (installation fee, opening balance, one-off charge...).
     * $items: [['description','unit_price','quantity','discount','package_id'?], ...]
     */
    public static function createManual(Customer $customer, array $data, array $items, bool $asDraft = false): Invoice
    {
        return DB::transaction(function () use ($customer, $data, $items, $asDraft) {
            $invoiceDate = Carbon::parse($data['invoice_date'] ?? now());
            $invoice = self::createInvoiceRecord($customer, $customer->branch_id, [
                'connection_id' => $data['connection_id'] ?? null,
                'invoice_date' => $invoiceDate,
                'due_date' => Carbon::parse($data['due_date'] ?? $invoiceDate->copy()->addDays(IspSettings::get($customer->branch_id, 'due_days'))),
                'discount' => $data['discount'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'source' => 'manual',
                'ledger_type' => $data['ledger_type'] ?? 'invoice',
            ], $items, ! $asDraft);

            if (! $asDraft) {
                CollectionService::autoAllocate($customer->id);
            }
            return $invoice->fresh(['items']);
        });
    }

    // Draft invoices are freely editable; nothing reaches the ledger until issue().
    public static function updateDraft(Invoice $invoice, array $data, array $items): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $items) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'draft') {
                throw new RuntimeException('Only draft invoices can be edited. Use a credit/debit note or void for issued invoices.');
            }
            $old = $invoice->only(['subtotal', 'discount', 'total', 'invoice_date', 'due_date']);
            $invoice->items()->delete();
            [$subtotal] = self::writeItems($invoice, $items);
            $invoice->fill([
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'due_date' => $data['due_date'] ?? $invoice->due_date,
                'connection_id' => $data['connection_id'] ?? $invoice->connection_id,
                'discount' => round((float) ($data['discount'] ?? 0), 2),
                'notes' => $data['notes'] ?? $invoice->notes,
                'subtotal' => $subtotal,
                'updated_by' => Auth::guard('web')->id(),
            ]);
            self::assertDiscount($invoice);
            $invoice->total = round($subtotal - (float) $invoice->discount, 2);
            $invoice->due = $invoice->total;
            $invoice->save();
            AuditLogger::log('invoice.draft_updated', $invoice, $old, $invoice->only(['subtotal', 'discount', 'total', 'invoice_date', 'due_date']));
            return $invoice->fresh(['items']);
        });
    }

    public static function issue(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'draft') {
                throw new RuntimeException('Only a draft invoice can be issued.');
            }
            $invoice->status = 'issued';
            $invoice->save();
            self::postInvoiceToLedger($invoice, 'invoice');
            self::recalculate($invoice);
            AuditLogger::log('invoice.issued', $invoice, ['status' => 'draft'], ['status' => $invoice->status]);
            CollectionService::autoAllocate($invoice->customer_id);
            return $invoice->fresh();
        });
    }

    // Void: money already allocated goes back to the customer's advance credit, the
    // ledger gets an opposite entry, and the period becomes billable again.
    public static function void(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if (in_array($invoice->status, ['void', 'cancelled'], true)) {
                throw new RuntimeException('Invoice is already void.');
            }
            $old = $invoice->only(['status', 'total', 'paid', 'due']);

            if ($invoice->status === 'draft') {
                $invoice->status = 'cancelled';
                $invoice->period_key = null;
                $invoice->voided_at = now();
                $invoice->voided_by = Auth::guard('web')->id();
                $invoice->void_reason = $reason;
                $invoice->save();
                AuditLogger::log('invoice.cancelled', $invoice, $old, ['status' => 'cancelled'], $reason);
                return $invoice;
            }

            foreach ($invoice->allocations()->where('status', 'active')->get() as $allocation) {
                CollectionService::reverseAllocation($allocation, 'Invoice ' . $invoice->invoice_no . ' voided');
            }
            $invoice->refresh();

            LedgerService::post($invoice->customer_id, $invoice->branch_id, 'invoice_void', 0, (float) $invoice->total,
                "Invoice {$invoice->invoice_no} voided: {$reason}", 'invoice', $invoice->id);

            $invoice->status = 'void';
            $invoice->period_key = null;
            $invoice->due = 0;
            $invoice->voided_at = now();
            $invoice->voided_by = Auth::guard('web')->id();
            $invoice->void_reason = $reason;
            $invoice->save();

            self::refreshExpiry($invoice);
            AuditLogger::log('invoice.voided', $invoice, $old, ['status' => 'void'], $reason);
            CollectionService::autoAllocate($invoice->customer_id);
            return $invoice;
        });
    }

    // Credit note lowers an invoice's total (max: its unpaid part), debit note raises it.
    public static function addNote(Invoice $invoice, string $type, float $amount, string $reason, $date = null): BillingNote
    {
        $note = DB::transaction(function () use ($invoice, $type, $amount, $reason, $date) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($invoice->status, ['issued', 'partially_paid', 'paid', 'overdue'], true)) {
                throw new RuntimeException('Notes can only be raised against an issued invoice.');
            }
            $amount = round($amount, 2);
            if ($amount <= 0) {
                throw new RuntimeException('Amount must be greater than zero.');
            }
            if ($type === 'credit' && $amount > (float) $invoice->due + 0.001) {
                throw new RuntimeException('A credit note cannot exceed the unpaid amount (' . number_format((float) $invoice->due, 2) . '). Reverse the payment allocation first if the invoice is already paid.');
            }

            $settings = IspSettings::all($invoice->branch_id);
            $note = BillingNote::create([
                'note_no' => SequenceService::next($invoice->branch_id, $type . '_note', $settings[$type . '_note_prefix']),
                'type' => $type,
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'note_date' => Carbon::parse($date ?? now())->toDateString(),
                'reason' => mb_substr($reason, 0, 255),
                'branch_id' => $invoice->branch_id,
                'created_by' => Auth::guard('web')->id(),
                'ipAddress' => request()->ip(),
            ]);

            $old = $invoice->only(['adjustment', 'total', 'due', 'status']);
            $invoice->adjustment = round((float) $invoice->adjustment + ($type === 'debit' ? $amount : -$amount), 2);
            $invoice->save();
            self::recalculate($invoice);

            LedgerService::post($invoice->customer_id, $invoice->branch_id, $type . '_note',
                $type === 'debit' ? $amount : 0, $type === 'credit' ? $amount : 0,
                ucfirst($type) . " note {$note->note_no} on {$invoice->invoice_no}: {$reason}", 'billing_note', $note->id, $note->note_date);

            AuditLogger::log('invoice.' . $type . '_note', $invoice, $old, $invoice->only(['adjustment', 'total', 'due', 'status']) + ['note_no' => $note->note_no], $reason);
            if ($type === 'debit') {
                CollectionService::autoAllocate($invoice->customer_id);
            }
            return $note;
        });

        OverdueService::reactivateIfClear($invoice->customer_id);
        return $note;
    }

    // Derives paid / due / status from allocations. Never trusts stored totals.
    public static function recalculate(Invoice $invoice): Invoice
    {
        $paid = round((float) PaymentAllocation::where('invoice_id', $invoice->id)->where('status', 'active')->sum('amount'), 2);
        $total = round((float) $invoice->subtotal - (float) $invoice->discount + (float) $invoice->adjustment, 2);

        $invoice->paid = $paid;
        $invoice->total = $total;

        if (in_array($invoice->status, ['draft', 'void', 'cancelled'], true)) {
            $invoice->due = in_array($invoice->status, ['draft'], true) ? $total : 0;
            $invoice->save();
            return $invoice;
        }

        $invoice->due = max(0, round($total - $paid, 2));
        $wasPaid = (bool) $invoice->paid_at;
        // the moment it became fully paid: a service invoice's internet time starts from here
        $invoice->paid_at = $invoice->due <= 0 ? ($invoice->paid_at ?? now()) : null;
        if ($invoice->due <= 0) {
            $invoice->status = 'paid';
        } elseif ($invoice->due_date->lt(now()->startOfDay())) {
            $invoice->status = 'overdue';
        } elseif ($paid > 0) {
            $invoice->status = 'partially_paid';
        } else {
            $invoice->status = 'issued';
        }
        $invoice->save();
        self::refreshExpiry($invoice);
        if (! $wasPaid && $invoice->paid_at) {
            ReferralService::rewardIfDue($invoice);
        }
        return $invoice;
    }

    // A service invoice paid (or unpaid again) moves its connection's expire time.
    private static function refreshExpiry(Invoice $invoice): void
    {
        if ($invoice->connection_id && $invoice->service_months) {
            ConnectionService::refreshExpiry($invoice->connection_id);
        }
    }

    private static function createInvoiceRecord(Customer $customer, int $branchId, array $data, array $items, bool $issue): Invoice
    {
        $ledgerType = $data['ledger_type'] ?? 'invoice';
        unset($data['ledger_type']);

        $invoice = new Invoice(array_merge($data, [
            'invoice_no' => SequenceService::next($branchId, 'invoice', IspSettings::get($branchId, 'invoice_prefix')),
            'customer_id' => $customer->id,
            'status' => $issue ? 'issued' : 'draft',
            'branch_id' => $branchId,
            'created_by' => Auth::guard('web')->id(),
            'ipAddress' => app()->runningInConsole() ? null : request()->ip(),
        ]));
        $invoice->discount = round((float) ($data['discount'] ?? 0), 2);

        try {
            $invoice->save();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'period_key')) {
                throw new RuntimeException('This period is already invoiced for the connection.');
            }
            throw $e;
        }

        [$subtotal] = self::writeItems($invoice, $items);
        $invoice->subtotal = $subtotal;
        self::assertDiscount($invoice);
        $invoice->total = round($subtotal - (float) $invoice->discount, 2);
        $invoice->due = $invoice->total;
        $invoice->save();

        if ($issue) {
            self::postInvoiceToLedger($invoice, $ledgerType);
            self::recalculate($invoice);
        }
        AuditLogger::log($issue ? 'invoice.created' : 'invoice.draft_created', $invoice, null,
            $invoice->only(['invoice_no', 'customer_id', 'connection_id', 'total', 'due_date', 'source']), null, $branchId);

        return $invoice;
    }

    private static function writeItems(Invoice $invoice, array $items): array
    {
        if (empty($items)) {
            throw new RuntimeException('An invoice needs at least one item.');
        }
        $subtotal = 0;
        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 1);
            $price = round((float) ($item['unit_price'] ?? 0), 2);
            $discount = round((float) ($item['discount'] ?? 0), 2);
            if ($qty <= 0 || $price < 0 || $discount < 0 || empty($item['description'])) {
                throw new RuntimeException('Every item needs a description, a positive quantity and a non-negative price.');
            }
            $lineTotal = round($price * $qty - $discount, 2);
            if ($lineTotal < 0) {
                throw new RuntimeException("Item discount is larger than the item amount ({$item['description']}).");
            }
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'package_id' => $item['package_id'] ?? null,
                'description' => mb_substr($item['description'], 0, 255),
                'unit_price' => $price,
                'quantity' => $qty,
                'discount' => $discount,
                'total' => $lineTotal,
                'period_start' => $item['period_start'] ?? null,
                'period_end' => $item['period_end'] ?? null,
            ]);
            $subtotal += $lineTotal;
        }
        return [round($subtotal, 2)];
    }

    private static function assertDiscount(Invoice $invoice): void
    {
        if ((float) $invoice->discount < 0 || (float) $invoice->discount > (float) $invoice->subtotal) {
            throw new RuntimeException('Invoice discount must be between 0 and the subtotal.');
        }
    }

    private static function postInvoiceToLedger(Invoice $invoice, string $type): void
    {
        $label = $type === 'opening' ? 'Opening balance' : 'Invoice';
        $period = $invoice->period_start ? " ({$invoice->period_start->format('d M')} – {$invoice->period_end->format('d M Y')})" : '';
        LedgerService::post($invoice->customer_id, $invoice->branch_id, $type, (float) $invoice->total, 0,
            "{$label} {$invoice->invoice_no}{$period}", 'invoice', $invoice->id, $invoice->invoice_date);
    }
}
