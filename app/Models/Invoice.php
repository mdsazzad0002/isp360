<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'period_start' => 'datetime:Y-m-d H:i:s',
        'period_end' => 'datetime:Y-m-d H:i:s',
        'paid_at' => 'datetime:Y-m-d H:i:s',
        'credit_at' => 'datetime:Y-m-d H:i:s',
        'invoice_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'subtotal' => MoneyCast::class,
        'discount' => MoneyCast::class,
        'tax' => MoneyCast::class,
        'tax_total' => MoneyCast::class,
        'tax_inclusive' => 'boolean',
        'adjustment' => MoneyCast::class,
        'total' => MoneyCast::class,
        'paid' => MoneyCast::class,
        'due' => MoneyCast::class,
        'voided_at' => 'datetime',
    ];

    // Statuses that still expect money.
    public const OPEN_STATUSES = ['issued', 'partially_paid', 'overdue'];

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function connection()
    {
        return $this->belongsTo(Connection::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function notes()
    {
        return $this->hasMany(BillingNote::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by')->select('id', 'name', 'username')->withTrashed();
    }
}
