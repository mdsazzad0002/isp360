<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Bank extends Model
{
    use HasFactory, SoftDeletes, Concerns\Audited;

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


    // cash balance
    public static function getBankBalance($request, $date = null)
    {
        $request = (object)$request;
        $branchId = (int) session('branch')->id;
        $date = sqlDate($date);
        $clauses = "";
        if(!empty($request->bankId)){
            $clauses .= " and ba.id = '" . (int) $request->bankId . "'";
        }

        $query = "select ba.id, ba.name, ba.number, ba.type, ba.bank_name,
                    (select ifnull(sum(bt.amount), 0) from bank_transactions bt
                    where bt.status = 'a'
                    and bt.bank_id = ba.id
                    and bt.type = 'debit'
                    " . ($date == null ? "" : " and bt.date <= '$date'") . "
                    " . ($branchId == null ? "" : " and bt.branch_id = '$branchId'") . ") as total_debit,

                    (select ifnull(sum(bt.amount), 0) from bank_transactions bt
                    where bt.status = 'a'
                    and bt.bank_id = ba.id
                    and bt.type = 'credit'
                    " . ($date == null ? "" : " and bt.date <= '$date'") . "
                    " . ($branchId == null ? "" : " and bt.branch_id = '$branchId'") . ") as total_credit,

                    (select ifnull(sum(cpp.amount), 0) from payments cpp
                    where cpp.status = 'a'
                    and cpp.type = 'customer'
                    and cpp.payment_method = 'bank'
                    and cpp.bank_id = ba.id
                    " . ($date == null ? "" : " and cpp.date <= '$date'") . "
                    " . ($branchId == null ? "" : " and cpp.branch_id = '$branchId'") . ") as total_paid_customer,

                    (select ifnull(sum(cpr.amount), 0) from receives cpr
                    where cpr.status = 'a'
                    and cpr.type = 'customer'
                    and cpr.payment_method = 'bank'
                    and cpr.bank_id = ba.id
                    " . ($date == null ? "" : " and cpr.date <= '$date'") . "
                    " . ($branchId == null ? "" : " and cpr.branch_id = '$branchId'") . ") as total_receive_customer,

                    (select ifnull(sum(spp.amount), 0) from payments spp
                    where spp.status = 'a'
                    and spp.type = 'supplier'
                    and spp.payment_method = 'bank'
                    and spp.bank_id = ba.id
                    " . ($date == null ? "" : " and spp.date <= '$date'") . "
                    " . ($branchId == null ? "" : " and spp.branch_id = '$branchId'") . ") as total_paid_supplier,

                    (select ifnull(sum(prp.amount), 0) from payments prp
                    where prp.status = 'a'
                    and prp.type = 'provider'
                    and prp.payment_method = 'bank'
                    and prp.bank_id = ba.id
                    " . ($date == null ? "" : " and prp.date <= '$date'") . "
                    " . ($branchId == null ? "" : " and prp.branch_id = '$branchId'") . ") as total_paid_provider,

                    (select ifnull(sum(emp.amount), 0) from payments emp
                    where emp.status = 'a'
                    and emp.type = 'employee'
                    and emp.payment_method = 'bank'
                    and emp.bank_id = ba.id
                    " . ($date == null ? "" : " and emp.date <= '$date'") . "
                    " . ($branchId == null ? "" : " and emp.branch_id = '$branchId'") . ") as total_paid_employee,

                    (select ifnull(sum(spr.amount), 0) from receives spr
                    where spr.status = 'a'
                    and spr.type = 'supplier'
                    and spr.payment_method = 'bank'
                    and spr.bank_id = ba.id
                    " . ($date == null ? "" : " and spr.date <= '$date'") . "
                    " . ($branchId == null ? "" : " and spr.branch_id = '$branchId'") . ") as total_receive_supplier,

                    (select ba.balance + total_credit + total_receive_customer + total_receive_supplier) as total_in_amount,
                    (select total_debit + total_paid_supplier + total_paid_provider + total_paid_employee + total_paid_customer) as total_out_amount,

                    (select total_in_amount - total_out_amount) as currentbalance
                    from banks ba
                    where ba.status = 'a'
                    " . ($branchId == null ? "" : " and ba.branch_id = '$branchId'") . "
                    $clauses";

        return DB::select($query);
    }
}
