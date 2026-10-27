<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One network problem from start to recovery (OLT unreachable, PON port down, ONU weak signal).
class NetworkAlert extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public const KINDS = ['olt_down' => 'OLT unreachable', 'pon_down' => 'PON port down', 'onu_weak' => 'Weak signal'];

    public function olt()
    {
        return $this->belongsTo(Olt::class)->select('id', 'name', 'host');
    }
}
