<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyProfile extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['radius_self_secret'];

    protected $casts = [
        'favicon_sizes' => 'array',
        'radius_self_secret' => 'encrypted',
    ];
}
