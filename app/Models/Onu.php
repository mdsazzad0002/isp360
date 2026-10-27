<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Onu extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'rx_power' => 'float',
        'tx_power' => 'float',
        'last_seen_at' => 'datetime',
        'last_polled_at' => 'datetime',
    ];

    public const STATUSES = ['online', 'offline', 'los', 'dying_gasp', 'auth_failed', 'not_found', 'unknown'];

    public function olt()
    {
        return $this->belongsTo(Olt::class)->select('id', 'name', 'vendor', 'host');
    }

    public function connection()
    {
        return $this->belongsTo(Connection::class)->select('id', 'code', 'customer_id', 'pppoe_username', 'status', 'box_id');
    }

    // RX power below the alert threshold (config isp.olt.weak_rx_dbm)
    public function isWeak(): bool
    {
        return $this->rx_power !== null && $this->rx_power < (float) config('isp.olt.weak_rx_dbm', -27);
    }
}
