<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A KYC document of a customer (ID card, passport, contract...). The file stays on the private disk.
class CustomerDocument extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['file_path'];

    protected $casts = ['verified_at' => 'datetime'];

    protected $appends = ['has_file'];

    public function getHasFileAttribute(): bool
    {
        return (bool) $this->file_path;
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by')->select('id', 'name')->withTrashed();
    }
}
