<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionVisit extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public const OUTCOMES = ['paid' => 'Paid', 'not_home' => 'Not at home', 'promised' => 'Will pay later', 'refused' => 'Refused', 'other' => 'Other'];

    public function customer()
    {
        return $this->belongsTo(Customer::class)->select('id', 'code', 'name', 'phone')->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class)->select('id', 'name')->withTrashed();
    }
}
