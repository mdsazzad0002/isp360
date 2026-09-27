<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A consent given or withdrawn by a customer: terms / privacy version accepted, marketing SMS.
// Append-only: the latest row of a type is the current state.
class CustomerConsent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['granted' => 'boolean', 'created_at' => 'datetime'];
}
