<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;

// Credit note (reduces what the customer owes) or debit note (increases it).
class BillingNote extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => MoneyCast::class, 'tax_amount' => MoneyCast::class, 'note_date' => 'date:Y-m-d'];

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by')->select('id', 'name', 'username')->withTrashed();
    }
}
