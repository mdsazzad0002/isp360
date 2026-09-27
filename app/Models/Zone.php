<?php

namespace App\Models;

use App\Models\Concerns\HasAuditUsers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Zone extends Model
{
    use SoftDeletes, HasAuditUsers;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function areas()
    {
        return $this->hasMany(Area::class);
    }
}
