<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectionHistory extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by')->select('id', 'name', 'username')->withTrashed();
    }
}
