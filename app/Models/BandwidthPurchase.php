<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;

class BandwidthPurchase extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'bandwidth_mbps' => 'decimal:2',
        'monthly_cost' => MoneyCast::class,
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public const TYPES = ['iig', 'nttn', 'transit', 'cache', 'other'];

    // Running on $date: started, and not ended before it.
    public function scopeRunningOn($query, string $date)
    {
        return $query->where('start_date', '<=', $date)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date));
    }
}
