<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['serialized' => 'boolean', 'is_active' => 'boolean', 'unit_cost' => 'float', 'low_stock' => 'float'];

    public const CATEGORIES = ['onu' => 'ONU / ONT', 'router' => 'Wi-Fi router', 'switch' => 'Switch', 'sfp' => 'SFP module', 'cable' => 'Cable', 'splitter' => 'Splitter', 'other' => 'Other'];

    public function serials()
    {
        return $this->hasMany(InventorySerial::class, 'item_id');
    }
}
