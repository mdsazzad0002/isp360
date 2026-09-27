<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'invoice_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'adjustment' => 'decimal:2',
        'total' => 'decimal:2',
        'paid' => 'decimal:2',
        'due' => 'decimal:2',
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
