<?php

namespace App\Models;

use App\Models\Concerns\StampsCreatedAt;
use Illuminate\Database\Eloquent\Model;

class PackagePriceHistory extends Model
{
    use StampsCreatedAt;

    public $timestamps = false;

    protected $guarded = ['id'];

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by')->select('id', 'name', 'username')->withTrashed();
    }
}
