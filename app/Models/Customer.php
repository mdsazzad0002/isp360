<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Customer extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = [
        'password',
    ];

    public function reseller()
    {
        return $this->belongsTo(Reseller::class, 'reseller_id', 'id')->select('id', 'name', 'username')->withTrashed();
    }

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

    public function area()
    {
        return $this->belongsTo(Area::class, 'area_id', 'id')->select('id', 'name')->withTrashed();
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class)->select('id', 'name')->withTrashed();
    }

    public function box()
    {
        return $this->belongsTo(Box::class)->select('id', 'name', 'code')->withTrashed();
    }

    public function connections()
    {
        return $this->hasMany(Connection::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function customerPayments()
    {
        return $this->hasMany(CustomerPayment::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class);
    }

    // customer due
    public static function customerDue($request, $date = null)
    {
        return DB::select(self::customerDueBaseQuery($request, $date));
    }

    public static function customerDuePaginated($request, $date, $search, $sortBy, $sortDir, $perPage, $page)
    {
        $req = (object) $request;
        $base = self::customerDueBaseQuery($request, $date);

        $bindings = [];
        $wrapClauses = "";
        if (!empty($search)) {
            $wrapClauses .= " and (t.name like ? or t.code like ? or t.phone like ? or t.address like ?)";
            $like = '%' . $search . '%';
            array_push($bindings, $like, $like, $like, $like);
        }
        if (empty($req->customerId)) {
            $wrapClauses .= " and t.due != 0";
        }

        $sortableColumns = ['code', 'name', 'phone', 'address', 'due'];
        $sortBy = in_array($sortBy, $sortableColumns) ? $sortBy : 'name';
        $sortDir = strtolower($sortDir) === 'desc' ? 'desc' : 'asc';
        $perPage = max(1, (int) $perPage);
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $perPage;

        $total = DB::selectOne("select count(*) as total from ($base) as t where 1=1 $wrapClauses", $bindings)->total;

        $rows = DB::select("select * from ($base) as t where 1=1 $wrapClauses order by t.$sortBy $sortDir limit $perPage offset $offset", $bindings);

        return [
            'data' => $rows,
            'total' => (int) $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    private static function customerDueBaseQuery($request, $date = null)
    {
        $request = (object)$request;
        $branchId = (int) session('branch')->id;
        $date = sqlDate($date);
        $clauses = "";
        if (!empty($request->customerId)) {
            $clauses .= " and c.id = '" . (int) $request->customerId . "'";
        }
        if (!empty($request->areaId)) {
            $clauses .= " and c.area_id = '" . (int) $request->areaId . "'";
        }

        if (!empty($request->customer_type) && in_array($request->customer_type, ['retail', 'wholesale'], true)) {
            $clauses .= " and c.type = '$request->customer_type'";
        }

        $query = "select
                    c.id, c.code, c.name, c.owner, c.phone, c.address, c.previous_due,

                    (select ifnull(sum(cr.amount), 0) from receives cr
                    where cr.status = 'a'
                    and cr.type = 'customer'
                    and cr.customer_payment_id is null
                    " . ($date == null ? "" : " and cr.date <= '$date'") . "
                    " . ($branchId == null ? "" : " and cr.branch_id = '$branchId'") . "
                    and cr.customer_id = c.id) as received_amount,

                    (select ifnull(sum(cp.amount), 0) from payments cp
                    where cp.status = 'a'
                    and cp.type = 'customer'
                    and cp.refund_id is null
                    " . ($date == null ? "" : " and cp.date <= '$date'") . "
                    " . ($branchId == null ? "" : " and cp.branch_id = '$branchId'") . "
                    and cp.customer_id = c.id) as payment_amount,

                    (select (c.previous_due + payment_amount) - received_amount) as due

                    from customers c
                    where c.status = 'a'
                    $clauses
                    " . ($branchId == null ? "" : " and c.branch_id = '$branchId'") . "";

        return $query;
    }
}
