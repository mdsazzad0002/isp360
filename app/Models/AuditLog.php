<?php

namespace App\Models;

use App\Models\Concerns\StampsCreatedAt;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use StampsCreatedAt;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class)->select('id', 'name', 'username')->withTrashed();
    }

    // Only meaningful when user_type is 'reseller' (user_id then holds the reseller id).
    public function reseller()
    {
        return $this->belongsTo(Reseller::class, 'user_id')->select('id', 'name', 'username')->withTrashed();
    }
}
