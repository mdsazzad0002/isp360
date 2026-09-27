<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkBlock extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public function package()
    {
        return $this->belongsTo(Package::class)->select('id', 'name', 'network_profile')->withTrashed();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by')->select('id', 'name')->withTrashed();
    }
}
