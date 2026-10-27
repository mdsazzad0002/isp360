<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;

class CashHandover extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => MoneyCast::class, 'confirmed_at' => 'datetime'];

    public function collector()
    {
        return $this->belongsTo(User::class, 'collector_id')->select('id', 'name')->withTrashed();
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by')->select('id', 'name')->withTrashed();
    }
}
