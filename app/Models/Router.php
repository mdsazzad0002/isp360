<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Router extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['password', 'radius_secret'];

    public const DRIVERS = ['mikrotik' => 'MikroTik (REST API)', 'radius' => 'RADIUS (FreeRADIUS)'];
    // which speed attributes a RADIUS NAS understands (see RadiusDriver::rateAttributes)
    public const NAS_TYPES = ['mikrotik' => 'MikroTik', 'huawei' => 'Huawei BRAS', 'cisco' => 'Cisco BNG', 'other' => 'Other (speeds set on the NAS)'];

    protected $casts = [
        'password' => 'encrypted',
        'radius_secret' => 'encrypted',
        'use_https' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    public function isRadius(): bool
    {
        return $this->driver === 'radius';
    }

    public function baseUrl(): string
    {
        $scheme = $this->use_https ? 'https' : 'http';
        $port = $this->port ? ':' . $this->port : '';
        return "{$scheme}://{$this->host}{$port}/rest";
    }

    // The router a connection is managed on: its own, else the branch default.
    public static function forConnection(Connection $connection): ?self
    {
        if ($connection->router_id) {
            return self::where('is_active', true)->find($connection->router_id);
        }
        return self::where('branch_id', $connection->branch_id)->where('is_active', true)->where('is_default', true)->first();
    }
}
