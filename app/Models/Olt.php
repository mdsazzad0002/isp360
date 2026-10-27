<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Olt extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['snmp_community', 'cli_password', 'cli_enable_password'];

    protected $casts = [
        'snmp_community' => 'encrypted',
        'cli_password' => 'encrypted',
        'cli_enable_password' => 'encrypted',
        'provision' => 'array',
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
        'last_polled_at' => 'datetime',
    ];

    // [vendor => label] from config/olt_vendors.php
    public static function vendors(): array
    {
        return array_map(fn ($v) => $v['label'], config('olt_vendors', []));
    }

    public function vendorConfig(): array
    {
        return config("olt_vendors.{$this->vendor}", []);
    }

    // a vendor without OIDs is kept by hand (no SNMP polling)
    public function canPoll(): bool
    {
        return ! empty($this->vendorConfig()['oids']) && filled($this->snmp_community);
    }

    // ONU changes need a CLI login and the vendor's command templates
    public function canConfigure(): bool
    {
        return in_array($this->cli_protocol, ['ssh', 'telnet'], true) && filled($this->cli_username) && ! empty($this->vendorConfig()['cli']['commands']);
    }

    public function onus()
    {
        return $this->hasMany(Onu::class);
    }
}
