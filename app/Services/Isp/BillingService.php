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
     * Bill every billable connection of a branch up to the end of the target month.
     * Idempotent: each connection's next_billing_date advances in the same transaction
     * as its invoice, and the unique period_key blocks any duplicate period.
     *
     * @return array{created:int, skipped:int, failed:int, errors:array}
     */
    public static function generateForBranch(int $branchId, ?Carbon $runDate = null, ?array $connectionIds = null): array
    {
        $settings = IspSettings::all($branchId);
        $runDate = ($runDate ?? now())->copy()->startOfDay();
        $targetMonth = $settings['billing_month'] === 'previous' ? $runDate->copy()->subMonthNoOverflow() : $runDate->copy();
        $targetEnd = $targetMonth->copy()->endOfMonth()->startOfDay();

        $statuses = $settings['bill_suspended'] ? ['active', 'suspended'] : ['active'];
        $stats = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        Connection::where('branch_id', $branchId)
            ->whereIn('status', $statuses)
            ->whereNotNull('next_billing_date')
            ->where('next_billing_date', '<=', $targetEnd->toDateString())
            ->when($connectionIds, fn ($q) => $q->whereIn('id', $connectionIds))
            ->orderBy('id')
            ->chunkById(200, function ($connections) use ($targetEnd, $runDate, $settings, &$stats) {
                foreach ($connections as $connection) {
                    try {
                        $created = self::billConnection($connection->id, $targetEnd, $runDate, $settings);
                        $created > 0 ? $stats['created'] += $created : $stats['skipped']++;
                    } catch (\Throwable $e) {
                        $stats['failed']++;
                        $stats['errors'][] = "{$connection->code}: {$e->getMessage()}";
                        Log::error('ISP invoice generation failed', ['connection' => $connection->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        AuditLogger::log('billing.generated', null, null, [
            'target_month' => $targetEnd->format('Y-m'), 'created' => $stats['created'], 'failed' => $stats['failed'],
        ], null, $branchId);

        return $stats;
    }

    // Creates every missing period invoice for one connection up to $targetEnd. Returns count.
    private static function billConnection(int $connectionId, Carbon $targetEnd, Carbon $invoiceDate, array $settings): int
    {
        $invoices = DB::transaction(function () use ($connectionId, $targetEnd, $invoiceDate, $settings) {
            $connection = Connection::with('package', 'customer')->lockForUpdate()->find($connectionId);
            if (! $connection || ! $connection->package || empty($connection->next_billing_date)) {
                return [];
            }

            $created = [];
            $guard = 0;
            while ($connection->next_billing_date->lte($targetEnd) && $guard++ < 24) {
                [$start, $end, $amount, $label, $share] = self::nextPeriod($connection);

                $invoice = self::createInvoiceRecord($connection->customer, $connection->branch_id, [
                    'connection_id' => $connection->id,
                    'period_start' => $start,
                    'period_end' => $end,
                    'period_key' => $connection->id . ':' . $start->toDateString(),
                    'invoice_date' => $invoiceDate,
                    'due_date' => $invoiceDate->copy()->addDays($settings['due_days']),
                    'source' => 'auto',
                ] + self::resellerCost($connection, $amount, $share), [[
                    'package_id' => $connection->package_id,
                    'description' => $label,
                    'unit_price' => $amount,
                    'quantity' => 1,
                    'discount' => 0,
                    'period_start' => $start,
                    'period_end' => $end,
                ]], true);

                $connection->next_billing_date = $end->copy()->addDay();
                $connection->save();
                $created[] = $invoice;
            }
            if ($created) {
                CollectionService::autoAllocate($connection->customer_id);
            }
            return $created;
        });

        foreach ($invoices as $invoice) {
            IspNotifier::send($invoice->branch_id, $invoice->customer, 'invoice', [
                'invoice' => $invoice->invoice_no,
                'amount' => number_format((float) $invoice->total, 2),
                'due_date' => $invoice->due_date->format('d-m-Y'),
            ]);
        }
        return count($invoices);
    }

    // A reseller package invoice records the company's share for the period: the company price
    // the reseller last accepted (base_price), so a company price change counts only once the
    // reseller has reviewed it. A reseller package with no base package has no known share:
    // the whole amount is the company's.
    private static function resellerCost(Connection $connection, float $amount, float $share): array
    {
        $package = $connection->package;
        if (! $package->reseller_id) {
            return [];
        }
        $basePrice = $package->base_price ?? ($package->base_package_id ? $package->basePackage?->price : null);
        $cost = $basePrice !== null ? round((float) $basePrice * $share, 2) : $amount;
        return ['reseller_id' => $package->reseller_id, 'reseller_cost' => $cost];
    }

    // [start, end, amount, description, share of a full cycle] for the connection's next unbilled period.
    private static function nextPeriod(Connection $connection): array
    {
        $package = $connection->package;
        $months = $package->cycleMonths();
        $charge = $connection->monthlyCharge(); // package price is per billing cycle, minus discount
        $start = $connection->next_billing_date->copy()->startOfDay();
        $speed = $package->download_mbps ? " ({$package->download_mbps} Mbps)" : '';

        if ($start->day !== 1) {
            // First, partial month of a mid-month activation: prorate by days used.
            $end = $start->copy()->endOfMonth()->startOfDay();
            $days = $start->diffInDays($end) + 1;
            $amount = round($charge / $months * $days / $start->daysInMonth, 2);
            $label = "{$package->name}{$speed} — {$start->format('d M')} to {$end->format('d M Y')} (prorated {$days} days)";
            return [$start, $end, $amount, $label, $days / $start->daysInMonth / $months];
        }

        $end = $start->copy()->addMonthsNoOverflow($months)->subDay()->startOfDay();
        $period = $months === 1 ? $start->format('F Y') : "{$start->format('M Y')} – {$end->format('M Y')}";
        return [$start, $end, $charge, "{$package->name}{$speed} — {$period}", 1.0];
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

            // For a connection's latest period, rewind its billing pointer so the period can be reissued.
            if ($invoice->connection_id && $invoice->period_start) {
                $connection = Connection::lockForUpdate()->find($invoice->connection_id);
                if ($connection && $connection->next_billing_date && $connection->next_billing_date->eq($invoice->period_end->copy()->addDay())) {
                    $connection->next_billing_date = $invoice->period_start;
                    $connection->save();
                }
            }

            LedgerService::post($invoice->customer_id, $invoice->branch_id, 'invoice_void', 0, (float) $invoice->total,
                "Invoice {$invoice->invoice_no} voided: {$reason}", 'invoice', $invoice->id);

            $invoice->status = 'void';
            $invoice->period_key = null;
            $invoice->due = 0;
            $invoice->voided_at = now();
            $invoice->voided_by = Auth::guard('web')->id();
            $invoice->void_reason = $reason;
            $invoice->save();

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
        return $invoice;
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
