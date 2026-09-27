<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageHistory extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function oldPackage()
    {
        return $this->belongsTo(Package::class, 'old_package_id')->select('id', 'name')->withTrashed();
    }

    public function newPackage()
    {
        return $this->belongsTo(Package::class, 'new_package_id')->select('id', 'name')->withTrashed();
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by')->select('id', 'name', 'username')->withTrashed();
    }
}
