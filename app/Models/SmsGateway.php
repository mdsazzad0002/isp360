<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SmsGateway extends Model
{
    use HasFactory, SoftDeletes, Concerns\Audited;

    public $timestamps = false;

    protected $guarded = ['id'];

    // the API key, and the custom URL template that usually carries it, are encrypted at rest;
    // the key never goes back to the browser (has_api_key says whether one is saved)
    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'api_key' => 'encrypted',
        'url_template' => 'encrypted',
    ];

    protected $hidden = ['api_key'];

    protected $appends = ['has_api_key'];

    public function getHasApiKeyAttribute(): bool
    {
        return (string) $this->api_key !== '';
    }

    public function adUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'id')->select('id', 'name', 'username')->withTrashed();
    }
    public function upUser()
    {
        return $this->belongsTo(User::class, 'updated_by', 'id')->select('id', 'name', 'username')->withTrashed();
    }
}
