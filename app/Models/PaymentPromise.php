<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;

class PaymentPromise extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'promised_date' => 'date:Y-m-d',
        'amount' => MoneyCast::class,
        'reactivated' => 'boolean',
        'reminder_sent_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public const STATUSES = ['open', 'kept', 'broken', 'cancelled'];

    public function customer()
    {
        return $this->belongsTo(Customer::class)->select('id', 'code', 'name', 'phone', 'area_id')->withTrashed();
    }

    public function connection()
    {
        return $this->belongsTo(Connection::class)->select('id', 'code', 'status', 'expire_at', 'customer_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by')->select('id', 'name')->withTrashed();
    }
}
