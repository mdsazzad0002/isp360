<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A published version of the terms of service or the privacy notice (never edited, only superseded).
class LegalDocument extends Model
{
    public const TYPES = ['terms' => 'Terms of service', 'privacy' => 'Privacy notice'];

    protected $casts = ['published_at' => 'datetime'];

    protected $guarded = ['id'];
}
