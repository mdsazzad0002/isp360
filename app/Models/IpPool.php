<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// An address pool of a branch: static IPv4, IPv6 prefix delegation or deterministic CGNAT (IpamService).
class IpPool extends Model
{
    public const TYPES = ['static' => 'Static IPv4', 'ipv6_pd' => 'IPv6 prefix delegation', 'cgnat' => 'CGNAT (deterministic)'];

    protected $guarded = ['id'];
}
