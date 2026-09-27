<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'decimal:4',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
    ];
}
