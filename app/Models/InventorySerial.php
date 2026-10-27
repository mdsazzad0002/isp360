<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One serial-tracked unit and where it is now (store | tech:<user id> | conn:<connection id> | out).
class InventorySerial extends Model
{
    protected $guarded = ['id'];

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'item_id')->select('id', 'name', 'category', 'unit');
    }

    public function connection()
    {
        return $this->belongsTo(Connection::class)->select('id', 'code', 'customer_id', 'status');
    }
}
