<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Router extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['password', 'radius_secret'];


    protected $casts = [
        'password' => 'encrypted',
        'radius_secret' => 'encrypted',
        'use_https' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
        'monitor_interfaces' => 'array',
    ];

    // [driver => label] from config('isp.router_drivers')
    public static function drivers(): array
    {
        return array_map(fn ($d) => $d['label'], config('isp.router_drivers', []));
    }

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
