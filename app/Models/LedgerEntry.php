<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Append-only. Rows are only ever inserted by LedgerService; a correction is a new
// opposite entry, never an update or delete.
class LedgerEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['entry_date' => 'date:Y-m-d', 'debit' => 'decimal:2', 'credit' => 'decimal:2', 'created_at' => 'datetime'];

    protected static function booted()
    {
        static::updating(fn () => throw new \LogicException('Ledger entries are append-only.'));
        static::deleting(fn () => throw new \LogicException('Ledger entries are append-only.'));
    }
}
