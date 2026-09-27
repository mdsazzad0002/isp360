<?php

namespace App\Models;

use App\Models\Concerns\HasAuditUsers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Box extends Model
{
    use SoftDeletes, HasAuditUsers;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'capacity' => 'integer'];

    public function area()
    {
        return $this->belongsTo(Area::class)->withTrashed();
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function connections()
    {
        return $this->hasMany(Connection::class);
    }

    // Ports in use = connections on this box that still occupy a port.
    public function usedPortsCount()
    {
        return $this->connections()->whereNotIn('status', ['terminated'])->count();
    }
}
