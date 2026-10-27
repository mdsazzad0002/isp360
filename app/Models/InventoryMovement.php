<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Append-only: every change of stock is a movement from one place to another.
class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['qty' => 'float', 'unit_cost' => 'float'];

    public const TYPES = ['receive' => 'Received', 'issue' => 'Issued to technician', 'return' => 'Returned to store', 'install' => 'Installed', 'recover' => 'Recovered from customer', 'faulty' => 'Faulty', 'write_off' => 'Written off', 'adjust' => 'Stock count adjustment'];

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'item_id')->select('id', 'name', 'unit');
    }

    public function serial()
    {
        return $this->belongsTo(InventorySerial::class, 'serial_id')->select('id', 'serial');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by')->select('id', 'name');
    }
}
