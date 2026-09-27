<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One webhook / IPN event from a payment gateway, keyed by the provider's event id (unique per
// gateway), so a repeated delivery is recognised and not processed twice.
class GatewayEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
