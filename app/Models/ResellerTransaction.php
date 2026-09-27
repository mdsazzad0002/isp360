<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;

// Settlement between the company and a reseller: withdrawal requests (company pays the
// reseller their earnings) and deposits (reseller hands over cash they collected).
// Never edited once paid; see ResellerWalletService.
class ResellerTransaction extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class,
        'processed_at' => 'datetime',
    ];

    public function reseller()
    {
        return $this->belongsTo(Reseller::class)->select('id', 'code', 'name', 'phone')->withTrashed();
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class)->withTrashed();
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by')->select('id', 'name', 'username')->withTrashed();
    }
}
