<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

// Money received from a customer against ISP billing. Never edited or deleted once
// completed: mistakes are corrected by reversal (see CollectionService).
class CustomerPayment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payment_date' => 'date:Y-m-d',
        'amount' => MoneyCast::class,
        'allocated_amount' => MoneyCast::class,
        'refunded_amount' => MoneyCast::class,
        'tax_amount' => MoneyCast::class,
        'reversed_at' => 'datetime',
    ];

    public const METHODS = ['cash', 'bank', 'bkash', 'nagad', 'rocket', 'card', 'gateway', 'other'];

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class)->withTrashed();
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by')->select('id', 'name', 'username')->withTrashed();
    }

    // Money on this payment not yet applied to an invoice or refunded (i.e. advance credit).
    public function unallocated(): float
    {
        if (! in_array($this->status, ['completed', 'partially_refunded'], true)) {
            return 0;
        }
        return Money::round((float) $this->amount - (float) $this->allocated_amount - (float) $this->refunded_amount);
    }
}
