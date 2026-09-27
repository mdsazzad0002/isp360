<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One internet session: user, IP / NAT port block, device MAC, start and stop (see SessionLogService).
class SessionLog extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime:Y-m-d H:i:s',
        'stopped_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function connection()
    {
        return $this->belongsTo(Connection::class)->select('id', 'code', 'customer_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->select('id', 'code', 'name', 'phone')->withTrashed();
    }
}
