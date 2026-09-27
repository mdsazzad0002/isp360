<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'last_reply_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public const CATEGORIES = ['connection', 'slow_speed', 'billing', 'payment', 'installation', 'package_change', 'reseller_account', 'other'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const STATUSES = ['open', 'in_progress', 'waiting', 'resolved', 'closed'];

    public function customer()
    {
        return $this->belongsTo(Customer::class)->select('id', 'code', 'name', 'phone', 'reseller_id')->withTrashed();
    }

    public function reseller()
    {
        return $this->belongsTo(Reseller::class)->select('id', 'code', 'name', 'phone')->withTrashed();
    }

    public function connection()
    {
        return $this->belongsTo(Connection::class)->select('id', 'code', 'pppoe_username', 'status');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to')->select('id', 'name', 'username')->withTrashed();
    }

    public function replies()
    {
        return $this->hasMany(TicketReply::class)->orderBy('id');
    }
}
