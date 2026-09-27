<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One e-mail / WhatsApp message sent to a customer, with the provider's answer (SMS: sms_logs).
class NotificationLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['is_success' => 'boolean', 'created_at' => 'datetime'];
}
