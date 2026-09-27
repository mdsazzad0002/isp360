<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackagePriceHistory extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by')->select('id', 'name', 'username')->withTrashed();
    }
}
