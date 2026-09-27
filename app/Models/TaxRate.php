<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A sales tax rate (VAT / GST), company-wide. Invoices keep their own snapshot of the rates.
class TaxRate extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'rate' => 'float',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}
