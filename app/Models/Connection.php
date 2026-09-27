<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Models\Concerns\HasAuditUsers;
use Illuminate\Database\Eloquent\Model;

// A customer's actual internet service. Connection status is independent of the
// customer's account status (an active customer can have a suspended connection).
class Connection extends Model
{
    use HasAuditUsers;

    protected $guarded = ['id'];

    protected $hidden = ['pppoe_password'];

    protected $casts = [
        'pppoe_password' => 'encrypted',
        'discount' => MoneyCast::class,
        'installation_date' => 'date:Y-m-d',
        'activation_date' => 'date:Y-m-d',
        'next_billing_date' => 'date:Y-m-d',
        'activated_at' => 'datetime:Y-m-d H:i:s',
        'expire_at' => 'datetime:Y-m-d H:i:s',
        'suspended_at' => 'datetime',
        'terminated_at' => 'datetime',
        'network_synced_at' => 'datetime',
    ];

    public const STATUSES = ['pending', 'active', 'suspended', 'inactive', 'terminated'];

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function package()
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    public function box()
    {
        return $this->belongsTo(Box::class)->withTrashed();
    }

    public function router()
    {
        return $this->belongsTo(Router::class)->select('id', 'name', 'host');
    }

    public function histories()
    {
        return $this->hasMany(ConnectionHistory::class)->latest('id');
    }

    public function packageHistories()
    {
        return $this->hasMany(PackageHistory::class)->latest('id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function monthlyCharge(): float
    {
        $price = (float) ($this->package->price ?? 0);
        return max(0, Money::round($price - (float) $this->discount));
    }
}
