<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AccountHead extends Model
{
    use HasFactory, SoftDeletes;

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
    public static function getCashBalance($request, $date = null)
    {
        $request = (object)$request;
        $branchId = !empty($request->branchId) ? $request->branchId : session('branch')->id;

        $query = "select
                /* Received */
                (select ifnull(sum(ccp.amount), 0) from receives ccp
                where ccp.status = 'a'
                and ccp.type = 'customer'
                and ccp.payment_method = 'cash'
                " . ($date == null ? "" : " and ccp.date <= '$date'") . "
                " . ($branchId == null ? "" : " and ccp.branch_id = '$branchId'") . ") as receive_customer,

                (select ifnull(sum(scp.amount), 0) from receives scp
                where scp.status = 'a'
                and scp.type = 'supplier'
                and scp.payment_method = 'cash'
                " . ($date == null ? "" : " and scp.date <= '$date'") . "
                " . ($branchId == null ? "" : " and scp.branch_id = '$branchId'") . ") as receive_supplier,

                (select ifnull(sum(ti.amount), 0) from transactions ti
                where ti.status = 'a'
                and ti.type = 'income'
                " . ($date == null ? "" : " and ti.date <= '$date'") . "
                " . ($branchId == null ? "" : " and ti.branch_id = '$branchId'") . ") as income,

                (select ifnull(sum(bt.amount), 0) from bank_transactions bt
                where bt.status = 'a'
                and bt.type = 'debit'
                " . ($date == null ? "" : " and bt.date <= '$date'") . "
                " . ($branchId == null ? "" : " and bt.branch_id = '$branchId'") . ") as bank_withdraw,

                /* Payment */

                (select ifnull(sum(ccp.amount), 0) from payments ccp
                where ccp.status = 'a'
                and ccp.type = 'customer'
                and ccp.payment_method = 'cash'
                " . ($date == null ? "" : " and ccp.date <= '$date'") . "
                " . ($branchId == null ? "" : " and ccp.branch_id = '$branchId'") . ") as payment_customer,

                (select ifnull(sum(scp.amount), 0) from payments scp
                where scp.status = 'a'
                and scp.type = 'supplier'
                and scp.payment_method = 'cash'
                " . ($date == null ? "" : " and scp.date <= '$date'") . "
                " . ($branchId == null ? "" : " and scp.branch_id = '$branchId'") . ") as payment_supplier,

                (select ifnull(sum(prp.amount), 0) from payments prp
                where prp.status = 'a'
                and prp.type = 'provider'
                and prp.payment_method = 'cash'
                " . ($date == null ? "" : " and prp.date <= '$date'") . "
                " . ($branchId == null ? "" : " and prp.branch_id = '$branchId'") . ") as payment_provider,

                (select ifnull(sum(emp.amount), 0) from payments emp
                where emp.status = 'a'
                and emp.type = 'employee'
                and emp.payment_method = 'cash'
                " . ($date == null ? "" : " and emp.date <= '$date'") . "
                " . ($branchId == null ? "" : " and emp.branch_id = '$branchId'") . ") as payment_employee,

                (select ifnull(sum(te.amount), 0) from transactions te
                where te.status = 'a'
                and te.type = 'expense'
                " . ($date == null ? "" : " and te.date <= '$date'") . "
                " . ($branchId == null ? "" : " and te.branch_id = '$branchId'") . ") as expense,

                (select ifnull(sum(bt.amount), 0) from bank_transactions bt
                where bt.status = 'a'
                and bt.type = 'credit'
                " . ($date == null ? "" : " and bt.date <= '$date'") . "
                " . ($branchId == null ? "" : " and bt.branch_id = '$branchId'") . ") as bank_deposit,

                (select receive_customer + receive_supplier + income + bank_withdraw) as total_in_amount,
                (select payment_customer + payment_supplier + payment_provider + payment_employee + expense + bank_deposit) as total_out_amount,
                (select total_in_amount - total_out_amount) as cashbalance";

        return DB::select($query)[0];
    }
}
