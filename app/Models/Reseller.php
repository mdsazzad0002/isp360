<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Models\Concerns\HasTwoFactor;
use Illuminate\Notifications\Notifiable;

class Reseller extends Authenticatable
{
    use HasFactory, HasTwoFactor, Notifiable, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = [
        'password',
    ];

    // a new reseller sits at the top until ResellerChainService::setParent places it
    protected static function booted(): void
    {
        static::created(function (Reseller $reseller) {
            if (! $reseller->path) {
                $parent = $reseller->parent_id ? self::withTrashed()->find($reseller->parent_id) : null;
                $reseller->forceFill(['path' => ($parent?->path ?? '/') . $reseller->id . '/', 'depth' => $parent ? $parent->depth + 1 : 1])->saveQuietly();
            }
        });
    }

    public function parent()
    {
        return $this->belongsTo(Reseller::class, 'parent_id')->select('id', 'code', 'name', 'phone', 'parent_id', 'depth')->withTrashed();
    }

    public function children()
    {
        return $this->hasMany(Reseller::class, 'parent_id');
    }

    public function adUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'id')->select('id', 'name', 'username')->withTrashed();
    }
    public function upUser()
    {
        return $this->belongsTo(User::class, 'updated_by', 'id')->select('id', 'name', 'username')->withTrashed();
    }
    public function deUser()
    {
        return $this->belongsTo(User::class, 'deleted_by', 'id')->select('id', 'name', 'username')->withTrashed();
    }

    public function area()
    {
        return $this->belongsTo(Area::class, 'area_id', 'id')->select('id', 'name')->withTrashed();
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'id')->select('id', 'name', 'title')->withTrashed();
    }

    public function customers()
    {
        return $this->hasMany(Customer::class, 'reseller_id', 'id');
    }
}
