<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'unit_price' => MoneyCast::class,
        'quantity' => 'decimal:4',
        'discount' => MoneyCast::class,
        'total' => MoneyCast::class,
        'tax_amount' => MoneyCast::class,
        'taxes' => 'array',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
    ];
}
