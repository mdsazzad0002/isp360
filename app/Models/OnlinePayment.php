<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One online payment attempt by a customer (gateway checkout or manual TrxID).
// It only becomes money in the ledger once completed (see OnlinePaymentService).
class OnlinePayment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'payload' => 'array',
        'reviewed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $hidden = ['payload'];

    public const OPEN = ['initiated', 'pending_review'];

    public function customer()
    {
        return $this->belongsTo(Customer::class)->select('id', 'code', 'name', 'phone')->withTrashed();
    }

    public function customerPayment()
    {
        return $this->belongsTo(CustomerPayment::class)->select('id', 'receipt_no', 'status');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by')->select('id', 'name')->withTrashed();
    }

    public function gatewayLabel(): string
    {
        return PaymentGateway::GATEWAYS[$this->gateway]['label'] ?? $this->gateway;
    }

    // Appends a gateway response to the payload for troubleshooting.
    public function logPayload(string $step, $data): void
    {
        $payload = $this->payload ?? [];
        $payload[] = ['step' => $step, 'at' => now()->toDateTimeString(), 'data' => $data];
        $this->payload = $payload;
    }
}
