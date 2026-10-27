<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['signature'];

    protected $casts = [
        'scheduled_at' => 'datetime:Y-m-d H:i',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public const TYPES = ['installation' => 'New installation', 'shifting' => 'Shifting', 'repair' => 'Repair', 'disconnection' => 'Disconnection', 'pickup' => 'Equipment pickup', 'other' => 'Other'];
    public const STATUSES = ['new', 'assigned', 'in_progress', 'done', 'cancelled'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const OPEN = ['new', 'assigned', 'in_progress'];

    public function customer()
    {
        return $this->belongsTo(Customer::class)->select('id', 'code', 'name', 'phone', 'address', 'area_id')->withTrashed();
    }

    public function connection()
    {
        return $this->belongsTo(Connection::class)->select('id', 'code', 'pppoe_username', 'status', 'box_id');
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class)->select('id', 'ticket_no', 'subject', 'status');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to')->select('id', 'name', 'username')->withTrashed();
    }

    public function photos()
    {
        return $this->hasMany(WorkOrderPhoto::class);
    }

    public function materials()
    {
        return $this->hasMany(InventoryMovement::class)->with(['item', 'serial']);
    }
}
