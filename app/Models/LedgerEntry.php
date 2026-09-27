<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\StampsCreatedAt;
use Illuminate\Database\Eloquent\Model;

// Append-only. Rows are only ever inserted by LedgerService; a correction is a new
// opposite entry, never an update or delete.
class LedgerEntry extends Model
{
    use StampsCreatedAt;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['entry_date' => 'date:Y-m-d', 'debit' => MoneyCast::class, 'credit' => MoneyCast::class, 'created_at' => 'datetime'];

    protected static function booted()
    {
        static::updating(fn () => throw new \LogicException('Ledger entries are append-only.'));
        static::deleting(fn () => throw new \LogicException('Ledger entries are append-only.'));
    }
}
