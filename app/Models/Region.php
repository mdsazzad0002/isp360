<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A group of branches (division / state / city) between the company and its branches.
class Region extends Model
{
    protected $guarded = ['id'];

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }
}
