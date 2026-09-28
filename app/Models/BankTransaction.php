<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BankTransaction extends Model
{
    use HasFactory, SoftDeletes, Concerns\Audited;

    // amounts in the company currency's decimals (the columns hold 3)
    protected $casts = [
        'amount' => MoneyCast::class,
        'previous_balance' => MoneyCast::class,
    ];

    public $timestamps = false;

    protected $guarded = ['id'];

    public function adUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'id')->select('id', 'name', 'username')->withTrashed();
    }
    public function upUser()
    {
        return $this->belongsTo(User::class, 'updated_by', 'id')->select('id', 'name', 'username')->withTrashed();
    }
    public function deUser()
    {
        return $this->belongsTo(User::class, 'deleted_by', 'id')->select('id', 'name', 'username')->withTrashed();
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class, 'bank_id', 'id')->select('id', 'name', 'number', 'bank_name')->withTrashed();
    }
}
