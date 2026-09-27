<?php

namespace App\Models;

use App\Models\Concerns\HasAuditUsers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use SoftDeletes, HasAuditUsers;

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'price' => 'decimal:2',
        'installation_fee' => 'decimal:2',
        'activation_fee' => 'decimal:2',
        'base_price' => 'decimal:2',
        'pending_changes' => 'array',
        'approved_at' => 'datetime',
    ];

    // Fields a reseller customizes on their copy of a company package; everything
    // technical (speed, router profile, cycle, fees) always follows the base package.
    public const RESELLER_FIELDS = ['name', 'code', 'price', 'description', 'is_active'];
    public const INHERITED_FIELDS = ['download_mbps', 'upload_mbps', 'billing_cycle', 'validity_days', 'installation_fee', 'activation_fee', 'network_profile'];

    public const CYCLE_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12];

    public function cycleMonths(): int
    {
        return self::CYCLE_MONTHS[$this->billing_cycle] ?? 1;
    }

    public function reseller()
    {
        return $this->belongsTo(Reseller::class)->select('id', 'name', 'code')->withTrashed();
    }

    public function basePackage()
    {
        return $this->belongsTo(Package::class, 'base_package_id')->withTrashed();
    }

    public function resellerCopies()
    {
        return $this->hasMany(Package::class, 'base_package_id');
    }

    // A reseller package goes live once the company has approved it at least once.
    public function isLive(): bool
    {
        return $this->reseller_id === null || $this->approved_at !== null;
    }

    // Company packages work for any customer, except that a hidden one is a wholesale base:
    // a reseller's customer gets it only through the reseller's customized copy. A reseller's
    // own package works only for that reseller's customers, and only once approved.
    public function usableFor(Customer $customer): bool
    {
        if ($this->reseller_id === null) {
            return $this->visibility !== 'hidden' || empty($customer->reseller_id);
        }
        return $this->isLive() && (int) $this->reseller_id === (int) $customer->reseller_id;
    }

    public function unusableReason(Customer $customer): string
    {
        if ($this->reseller_id === null) {
            return "Package {$this->name} is a hidden (wholesale) package. Use the reseller's customized package for this customer.";
        }
        if (! $this->isLive()) {
            return "Package {$this->name} is waiting for company approval.";
        }
        return "Package {$this->name} belongs to a reseller and can only be used for that reseller's customers.";
    }

    public function connections()
    {
        return $this->hasMany(Connection::class);
    }

    public function priceHistories()
    {
        return $this->hasMany(PackagePriceHistory::class)->latest('id');
    }
}
