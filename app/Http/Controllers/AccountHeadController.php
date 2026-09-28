<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AccountHeadController extends Controller
{
    use Concerns\BranchScoped;

    // fields a request may set (audit C2: never every request field)
    private const FIELDS = ['name', 'type'];

    protected $userId;
    protected $branchId;
    public function __construct()
    {
        $this->middleware('auth');

        $this->middleware(function ($request, $next) {
            $this->branchId = $request->session()->get('branch')->id;
            $this->userId = auth()->user()->id;
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $accounthead = AccountHead::with('adUser', 'upUser', 'deUser')->where('branch_id', $this->branchId);
        if (!empty($request->type)) {
            $accounthead->where('type', $request->type);
        }
        $accounthead = $accounthead->latest()->get();
        return response()->json($accounthead);
    }

    public function create(Request $request)
    {
        if (!checkAccess('accounthead')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }

        $allowedPerPage = [20, 50, 100, 200, 500];
        $perPage = in_array((int) $request->perPage, $allowedPerPage) ? (int) $request->perPage : 20;

        $accountheads = $this->applySort($this->filteredAccountHeadQuery($request), $request)
            ->paginate($perPage)
            ->withQueryString();

        return \Inertia\Inertia::render('Account/AccountHead', [
            'accountheads' => $accountheads,
            'filters' => [
                'search' => $request->search ?? '',
                'type' => $request->type ?? '',
                'sortBy' => $request->sortBy ?? 'id',
                'sortDir' => $request->sortDir === 'asc' ? 'asc' : 'desc',
                'perPage' => $perPage,
            ],
        ]);
    }

    private function filteredAccountHeadQuery(Request $request)
    {
        return AccountHead::with('adUser', 'upUser')
            ->where('account_heads.branch_id', $this->branchId)
            ->when($request->type, fn ($q) => $q->where('account_heads.type', $request->type))
            ->when($request->search, function ($q) use ($request) {
                $q->where('account_heads.name', 'like', '%' . $request->search . '%');
            });
    }

    private function applySort($query, Request $request)
    {
        $sortDir = $request->sortDir === 'asc' ? 'asc' : 'desc';
        $sortBy = $request->sortBy ?? 'id';

        $sortableColumns = ['id', 'name', 'type'];

        if (in_array($sortBy, $sortableColumns)) {
            return $query->orderBy('account_heads.' . $sortBy, $sortDir);
        }

        return $query->orderBy('account_heads.id', 'desc');
    }

    public function exportExcel(Request $request)
    {
        if (!checkAccess('accounthead')) {
            abort(403);
        }

        $accountheads = $this->filteredAccountHeadQuery($request)->latest()->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Account Head List');

        $headers = ['Sl', 'Name', 'Type'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

        $row = 2;
        foreach ($accountheads as $index => $item) {
            $sheet->fromArray([$index + 1, $item->name, $item->type], null, 'A' . $row);
            $row++;
        }

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fileName = 'account-head-list-' . now()->format('Y-m-d') . '.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function importBatch(Request $request)
    {
        if (!checkAccess('accounthead')) {
            return response()->json(['status' => false, 'message' => 'Forbidden'], 403);
        }

        $rows = $request->input('rows', []);
        $inserted = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $row = array_change_key_case((array) $row, CASE_LOWER);
            $rowLabel = 'Row ' . ($i + 1);

            $name = trim($row['name'] ?? '');
            if ($name === '') {
                $skipped++;
                $errors[] = "$rowLabel: name is required";
                continue;
            }

            $exists = AccountHead::where('branch_id', $this->branchId)->where('name', $name)->exists();
            if ($exists) {
                $skipped++;
                $errors[] = "$rowLabel: \"$name\" already exists";
                continue;
            }

            $type = strtolower(trim($row['type'] ?? ''));
            if (!in_array($type, ['expense', 'income'])) {
                $skipped++;
                $errors[] = "$rowLabel: type must be expense or income";
                continue;
            }

            $accounthead = new AccountHead();
            $accounthead->name = $name;
            $accounthead->type = $type;
            $accounthead->status = 'a';
            $accounthead->created_by = $this->userId;
            $accounthead->ipAddress = request()->ip();
            $accounthead->branch_id = $this->branchId;
            $accounthead->save();

            $inserted++;
        }

        return response()->json(['status' => true, 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors]);
    }

    public function store(Request $request)
    {
        $branchId = $this->branchId;
        $validator = Validator::make($request->all(), [
            'name'     => [
                'required',
                Rule::unique('account_heads')
                    ->where(function ($query) use ($branchId) {
                        $query->where('branch_id', $branchId);
                    })
                    ->whereNull('deleted_at'),
            ],
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $check = AccountHead::where('name', $request->name)->where('branch_id', $this->branchId)->withTrashed()->first();
            if (!empty($check) && $check->deleted_at != NULL) {
                $check->status = 'a';
                $check->deleted_by = NULL;
                $check->deleted_at = NULL;
                $check->update();
                $data = $check;
            } else {
                $data = new AccountHead();
                [$fields, $error] = $this->branchFields($request, self::FIELDS);
                if ($error) return $error;
                $data->forceFill($fields);
                $data->created_by = $this->userId;
                $data->branch_id  = $this->branchId;
                $data->ipAddress  = request()->ip();
                $data->save();
            }

            return response()->json(['status' => true, 'message' => "Account Head has created successfully", 'account' => $data]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function update(Request $request)
    {
        $branchId = $this->branchId;
        $validator = Validator::make($request->all(), [
            'name'     => [
                'required',
                Rule::unique('account_heads')
                    ->ignore($request->id)
                    ->where(function ($query) use ($branchId) {
                        $query->where('branch_id', $branchId);
                    })
                    ->whereNull('deleted_at'),
            ],
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $data = $this->findInBranch(AccountHead::class, $request->id);
            if (!$data) return send_error('Record not found', null, 404);
            [$fields, $error] = $this->branchFields($request, self::FIELDS);
            if ($error) return $error;
            $data->forceFill($fields);
            $data->updated_at = Carbon::now();
            $data->updated_by = $this->userId;
            $data->ipAddress = request()->ip();
            $data->branch_id = $this->branchId;
            $data->update();

            return response()->json(['status' => true, 'message' => "Account Head has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function destroy(Request $request)
    {
        try {
            $data = $this->findInBranch(AccountHead::class, $request->id);
            if (!$data) return send_error('Record not found', null, 404);
            $data->deleted_by = $this->userId;
            $data->status = 'd';
            $data->ipAddress = request()->ip();
            $data->update();

            $data->delete();
            return response()->json(['status' => true, 'message' => "Account Head has deleted successfully"]);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }

    public function deletedAccountHeadRecord()
    {
        if (!checkAccess('accountheadRestore')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Account/AccountHead/DeletedRecord');
    }

    public function getDeletedAccountHead(Request $request)
    {
        $accountheads = AccountHead::onlyTrashed()->with('deUser')->where('branch_id', $this->branchId);
        if (!empty($request->search)) {
            $accountheads = $accountheads->where('name', 'like', '%' . $request->search . '%');
        }
        $accountheads = $accountheads->latest('deleted_at')->get()->map(function ($item) {
            $item->deleted_by_name = $item->deUser->name ?? 'NA';
            return $item;
        });
        return response()->json($accountheads);
    }

    public function restoreAccountHead(Request $request)
    {
        if (!checkAccess('accountheadRestore')) {
            return send_error('You are not authorized to restore account head', null, 403);
        }
        $accounthead = AccountHead::onlyTrashed()->where('branch_id', $this->branchId)->find($request->id);
        if (empty($accounthead)) {
            return send_error('Deleted account head not found', null, 404);
        }
        try {
            $accounthead->restore();
            $accounthead->status = 'a';
            $accounthead->deleted_by = null;
            $accounthead->ipAddress = request()->ip();
            $accounthead->update();

            return response()->json(['status' => true, 'message' => "Account Head has restored successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function cashLedger()
    {
        if (!checkAccess('cashLedger')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Report/SingleLedger', ['mode' => 'cash']);
    }

    public function cashBankLedger()
    {
        if (!checkAccess('cashLedger') && !checkAccess('bankLedger')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Report/CashBankLedger');
    }

    private function cashLedgerRows($branchId)
    {
        $query = "
                /*================= In Amount =======================*/
                select
                'b' as sequence,
                cpr.id,
                cpr.date,
                cpr.created_at,
                concat('Customer Payment - ', cpr.invoice) as description,
                cpr.amount as in_amount,
                0 as out_amount,
                0 as balance
                from receives cpr
                where cpr.status = 'a'
                and cpr.type = 'customer'
                and cpr.payment_method = 'cash'
                " . ($branchId == null ? "" : " and cpr.branch_id = '$branchId'") . "

                UNION
                select
                'c' as sequence,
                bt.id,
                bt.date,
                bt.created_at,
                concat('Bank Withdraw - ', bt.invoice) as description,
                bt.amount as in_amount,
                0 as out_amount,
                0 as balance
                from bank_transactions bt
                where bt.status = 'a'
                and bt.type = 'debit'
                " . ($branchId == null ? "" : " and bt.branch_id = '$branchId'") . "

                UNION
                select
                'd' as sequence,
                spr.id,
                spr.date,
                spr.created_at,
                concat('Supplier Receive - ', spr.invoice) as description,
                spr.amount as in_amount,
                0 as out_amount,
                0 as balance
                from receives spr
                where spr.status = 'a'
                and spr.type = 'supplier'
                and spr.payment_method = 'cash'
                " . ($branchId == null ? "" : " and spr.branch_id = '$branchId'") . "

                UNION
                select
                'g' as sequence,
                inc.id,
                inc.date,
                inc.created_at,
                concat('Income Invoice - ', inc.invoice) as description,
                inc.amount as in_amount,
                0 as out_amount,
                0 as balance
                from transactions inc
                where inc.status = 'a'
                and inc.type = 'income'
                " . ($branchId == null ? "" : " and inc.branch_id = '$branchId'") . "

                /*================= Out Amount =======================*/
                UNION
                select
                'j' as sequence,
                spp.id,
                spp.date,
                spp.created_at,
                concat('Supplier Payment - ', spp.invoice) as description,
                0 as in_amount,
                spp.amount as out_amount,
                0 as balance
                from payments spp
                where spp.status = 'a'
                and spp.type = 'supplier'
                and spp.payment_method = 'cash'
                " . ($branchId == null ? "" : " and spp.branch_id = '$branchId'") . "

                UNION
                select
                'r' as sequence,
                prp.id,
                prp.date,
                prp.created_at,
                concat('Provider Payment - ', prp.invoice) as description,
                0 as in_amount,
                prp.amount as out_amount,
                0 as balance
                from payments prp
                where prp.status = 'a'
                and prp.type = 'provider'
                and prp.payment_method = 'cash'
                " . ($branchId == null ? "" : " and prp.branch_id = '$branchId'") . "

                UNION
                select
                'v' as sequence,
                emp.id,
                emp.date,
                emp.created_at,
                concat('Employee Payment - ', emp.invoice) as description,
                0 as in_amount,
                emp.amount as out_amount,
                0 as balance
                from payments emp
                where emp.status = 'a'
                and emp.type = 'employee'
                and emp.payment_method = 'cash'
                " . ($branchId == null ? "" : " and emp.branch_id = '$branchId'") . "

                UNION
                select
                'k' as sequence,
                bt.id,
                bt.date,
                bt.created_at,
                concat('Bank Deposit - ', bt.invoice) as description,
                0 as in_amount,
                bt.amount as out_amount,
                0 as balance
                from bank_transactions bt
                where bt.status = 'a'
                and bt.type = 'credit'
                " . ($branchId == null ? "" : " and bt.branch_id = '$branchId'") . "

                UNION
                select
                'l' as sequence,
                cpp.id,
                cpp.date,
                cpp.created_at,
                concat('Customer Payment - ', cpp.invoice) as description,
                0 as in_amount,
                cpp.amount as out_amount,
                0 as balance
                from payments cpp
                where cpp.status = 'a'
                and cpp.type = 'customer'
                and cpp.payment_method = 'cash'
                " . ($branchId == null ? "" : " and cpp.branch_id = '$branchId'") . "

                UNION
                select
                'm' as sequence,
                exp.id,
                exp.date,
                exp.created_at,
                concat('Expense Invoice - ', exp.invoice) as description,
                0 as in_amount,
                exp.amount as out_amount,
                0 as balance
                from transactions exp
                where exp.status = 'a'
                and exp.type = 'expense'
                " . ($branchId == null ? "" : " and exp.branch_id = '$branchId'") . "

                -- the running balance follows the business date, so a back-dated entry lands where it belongs
                order by date asc, created_at asc, sequence asc, id asc";

        return collect(DB::select($query));
    }

    public function getCashLedger(Request $request)
    {
        $ledgers = $this->cashLedgerRows($this->branchId);

        $ledgers = $ledgers->map(function ($ledger, $key) use ($ledgers) {
            $lastBalance = $key == 0 ? 0 : $ledgers[$key - 1]->balance;
            $ledger->balance = ($lastBalance + $ledger->in_amount) - $ledger->out_amount;
            return $ledger;
        });

        $previousLedger = collect($ledgers)->filter(function ($ledger) use ($request) {
            return $ledger->date < $request->dateFrom;
        });
        $previousBalance = $previousLedger->isNotEmpty() ? $previousLedger->last()->balance : 0;

        if (!empty($request->dateFrom) && !empty($request->dateTo)) {
            $ledgers = $ledgers->filter(function ($ledger) use ($request) {
                return $ledger->date >= $request->dateFrom && $ledger->date <= $request->dateTo;
            })->values();
        }


        return response()->json(['previousBalance' => $previousBalance, 'ledgers' => $ledgers]);
    }

    // Per-amount drill-down for the Day Book's Opening/Receipt/Payment/Closing cash cells —
    // each cell must show only the entries behind that specific number, not the whole ledger.
    public function getDayBookCashDetail(Request $request)
    {
        $branchId = $this->branchId;
        $section = $request->section; // 'opening' | 'receipt' | 'payment' | 'closing'
        $dateFrom = $request->dateFrom;
        $dateTo = $request->dateTo;

        if (in_array($section, ['opening', 'closing'])) {
            $date = $section === 'opening' ? Carbon::parse($dateFrom)->subDay()->format('Y-m-d') : $dateTo;
            $row = AccountHead::getCashBalance(['branchId' => $branchId], $date);

            $labels = [
                'receive_customer' => ['Customer Cash Received', 'in'],
                'receive_supplier' => ['Supplier Cash Received (Return)', 'in'],
                'income' => ['Other Income', 'in'],
                'bank_withdraw' => ['Bank Withdraw (to Cash)', 'in'],
                'payment_customer' => ['Customer Payment (Cash)', 'out'],
                'payment_supplier' => ['Supplier Payment (Cash)', 'out'],
                'payment_provider' => ['Provider Payment (Cash)', 'out'],
                'payment_employee' => ['Employee Payment (Cash)', 'out'],
                'expense' => ['Expense', 'out'],
                'bank_deposit' => ['Bank Deposit (from Cash)', 'out'],
            ];

            $rows = [];
            foreach ($labels as $key => [$label, $direction]) {
                $amount = (float) ($row->$key ?? 0);
                if ($amount != 0) {
                    $rows[] = ['label' => $label, 'direction' => $direction, 'amount' => $amount];
                }
            }

            return response()->json([
                'type' => 'breakdown',
                'asOfDate' => $date,
                'total' => (float) $row->cashbalance,
                'rows' => $rows,
            ]);
        }

        // receipt / payment: the individual cash-in or cash-out transactions within the range
        $direction = $section === 'payment' ? 'out' : 'in';
        $rows = $this->cashLedgerRows($branchId)
            ->filter(function ($row) use ($dateFrom, $dateTo, $direction) {
                $amount = $direction === 'in' ? (float) $row->in_amount : (float) $row->out_amount;
                return $amount != 0 && $row->date >= $dateFrom && $row->date <= $dateTo;
            })
            ->map(function ($row) use ($direction) {
                return [
                    'date' => $row->date,
                    'description' => $row->description,
                    'amount' => $direction === 'in' ? (float) $row->in_amount : (float) $row->out_amount,
                ];
            })
            ->sortBy('date')
            ->values();

        return response()->json(['type' => 'list', 'rows' => $rows]);
    }

    public function getCashBalance(Request $request)
    {
        return response()->json(['cashbalance' => (float) AccountHead::getCashBalance([])->cashbalance]);
    }
}
