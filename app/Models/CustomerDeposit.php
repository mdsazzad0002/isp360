<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;

// A refundable security deposit held for a customer (see DepositService).
class CustomerDeposit extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class,
        'refunded_amount' => MoneyCast::class,
        'applied_amount' => MoneyCast::class,
        'received_date' => 'date:Y-m-d',
    ];

    protected $appends = ['held'];

    // still held: received minus refunded minus applied to dues
    public function getHeldAttribute(): float
    {
        return round((float) $this->amount - (float) $this->refunded_amount - (float) $this->applied_amount, 3);
    }

    public function connection()
    {
        return $this->belongsTo(Connection::class)->select('id', 'code');
    }
}
