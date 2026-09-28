<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class BankController extends Controller
{
    use Concerns\BranchScoped;

    // fields a request may set (audit C2: never every request field)
    private const FIELDS = ['name', 'number', 'type', 'branch_name', 'bank_name', 'balance'];

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
        $bank = Bank::with('adUser', 'upUser', 'deUser')
            ->latest()->get()->map(function ($item) {
                $item->display_name = $item->name . ' - ' . $item->number . ' - ' . $item->bank_name;
                return $item;
            });

        return response()->json($bank);
    }

    public function create()
    {
        if (!checkAccess('bank')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Account/Bank', [
            'role' => auth()->user()->role,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'number' => 'required',
            'type' => 'required',
            'balance' => 'required'
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        $check = Bank::where('bank_name', $request->bank_name)->where('number', $request->number)->where('branch_id', $this->branchId)->first();
        if (!empty($check)) {
            return send_error("Bank already exists", null, 422);
        }
        try {
            $check = Bank::where('name', $request->name)->where('branch_id', $this->branchId)->withTrashed()->first();
            if (!empty($check) && $check->deleted_at != NULL) {
                $check->status = 'a';
                $check->deleted_by = NULL;
                $check->deleted_at = NULL;
                $check->update();
            } else {
                $data = new Bank();
                [$fields, $error] = $this->branchFields($request, self::FIELDS);
                if ($error) return $error;
                $data->forceFill($fields);
                $data->created_by = $this->userId;
                $data->branch_id  = $this->branchId;
                $data->ipAddress  = request()->ip();
                $data->save();
            }

            return response()->json(['status' => true, 'message' => "Bank has created successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'number' => 'required',
            'type' => 'required',
            'balance' => 'required'
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        $check = Bank::where('id', '!=', $request->id)->where('bank_name', $request->bank_name)->where('number', $request->number)->where('branch_id', $this->branchId)->first();
        if (!empty($check)) {
            return send_error("Bank already exists", null, 422);
        }
        try {
            $data = $this->findInBranch(Bank::class, $request->id);
            if (!$data) return send_error('Record not found', null, 404);
            [$fields, $error] = $this->branchFields($request, self::FIELDS);
            if ($error) return $error;
            $data->forceFill($fields);
            $data->updated_at = Carbon::now();
            $data->updated_by = $this->userId;
            $data->ipAddress = request()->ip();
            $data->branch_id = $this->branchId;
            $data->update();

            return response()->json(['status' => true, 'message' => "Bank has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function destroy(Request $request)
    {
        try {
            $data = $this->findInBranch(Bank::class, $request->id);
            if (!$data) return send_error('Record not found', null, 404);
            $data->deleted_by = $this->userId;
            $data->status = 'd';
            $data->ipAddress = request()->ip();
            $data->update();

            $data->delete();
            return response()->json(['status' => true, 'message' => "Bank has deleted successfully"]);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }

    public function deletedBankRecord()
    {
        if (!checkAccess('bankRestore')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Account/Bank/DeletedRecord');
    }

    public function getDeletedBank(Request $request)
    {
        $banks = Bank::onlyTrashed()->with('deUser')->where('branch_id', $this->branchId);
        if (!empty($request->search)) {
            $banks = $banks->where(function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('number', 'like', '%' . $request->search . '%')
                    ->orWhere('bank_name', 'like', '%' . $request->search . '%');
            });
        }
        $banks = $banks->latest('deleted_at')->get()->map(function ($item) {
            $item->deleted_by_name = $item->deUser->name ?? 'NA';
            return $item;
        });
        return response()->json($banks);
    }

    public function restoreBank(Request $request)
    {
        if (!checkAccess('bankRestore')) {
            return send_error('You are not authorized to restore bank', null, 403);
        }
        $bank = Bank::onlyTrashed()->where('branch_id', $this->branchId)->find($request->id);
        if (empty($bank)) {
            return send_error('Deleted bank not found', null, 404);
        }
        try {
            $bank->restore();
            $bank->status = 'a';
            $bank->deleted_by = null;
            $bank->ipAddress = request()->ip();
            $bank->update();

            return response()->json(['status' => true, 'message' => "Bank has restored successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function exportExcel(Request $request)
    {
        if (!checkAccess('bank')) {
            abort(403);
        }

        $banks = Bank::where('branch_id', $this->branchId)
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($qq) use ($request) {
                    $qq->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('number', 'like', '%' . $request->search . '%')
                        ->orWhere('bank_name', 'like', '%' . $request->search . '%')
                        ->orWhere('branch_name', 'like', '%' . $request->search . '%');
                });
            })
            ->latest()
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bank List');

        $headers = ['Sl', 'Account Name', 'Account Number', 'Account Type', 'Bank Name', 'Branch Name', 'Balance', 'Status'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

        $row = 2;
        foreach ($banks as $index => $item) {
            $sheet->fromArray([
                $index + 1,
                $item->name,
                $item->number,
                $item->type,
                $item->bank_name,
                $item->branch_name,
                $item->balance,
                $item->status === 'a' ? 'Active' : 'Deactive',
            ], null, 'A' . $row);
            $row++;
        }

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fileName = 'bank-list-' . now()->format('Y-m-d') . '.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function getBankBalance(Request $request)
    {
        $dues = Bank::getBankBalance($request);

        return response()->json($dues);
    }

    public function bankLedger()
    {
        if (!checkAccess('bankLedger')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Report/SingleLedger', ['mode' => 'bank']);
    }

    private function bankLedgerRows($branchId, $bankId)
    {
        $branchId = $branchId === null ? null : (int) $branchId;
        $bankId = empty($bankId) ? null : (int) $bankId;
        $query = "
                select
                'a' as sequence,
                bt.id,
                bt.date,
                bt.created_at,
                concat('Bank Deposit - ', bt.invoice) as description,
                0 as withdraw,
                bt.amount as deposit,
                0 as balance,
                concat_ws(' - ', bk.name, bk.bank_name) as bank_name
                from bank_transactions bt
                left join banks bk on bk.id = bt.bank_id
                where bt.status = 'a'
                and bt.type = 'credit'
                " . (empty($bankId) ? "" : " and bt.bank_id = '$bankId'") . "
                " . ($branchId == null ? "" : " and bt.branch_id = '$branchId'") . "

                UNION
                select
                'c' as sequence,
                cpr.id,
                cpr.date,
                cpr.created_at,
                concat('Customer Payment - ', cpr.invoice) as description,
                0 as withdraw,
                cpr.amount as deposit,
                0 as balance,
                concat_ws(' - ', bk.name, bk.bank_name) as bank_name
                from receives cpr
                left join banks bk on bk.id = cpr.bank_id
                where cpr.status = 'a'
                and cpr.type = 'customer'
                and cpr.payment_method = 'bank'
                " . (empty($bankId) ? "" : " and cpr.bank_id = '$bankId'") . "
                " . ($branchId == null ? "" : " and cpr.branch_id = '$branchId'") . "

                UNION
                select
                'd' as sequence,
                spr.id,
                spr.date,
                spr.created_at,
                concat('Supplier Receive - ', spr.invoice) as description,
                0 as withdraw,
                spr.amount as deposit,
                0 as balance,
                concat_ws(' - ', bk.name, bk.bank_name) as bank_name
                from receives spr
                left join banks bk on bk.id = spr.bank_id
                where spr.status = 'a'
                and spr.type = 'supplier'
                and spr.payment_method = 'bank'
                " . (empty($bankId) ? "" : " and spr.bank_id = '$bankId'") . "
                " . ($branchId == null ? "" : " and spr.branch_id = '$branchId'") . "

                UNION
                select
                'e' as sequence,
                bt.id,
                bt.date,
                bt.created_at,
                concat('Bank Withdraw - ', bt.invoice) as description,
                bt.amount as withdraw,
                0 as deposit,
                0 as balance,
                concat_ws(' - ', bk.name, bk.bank_name) as bank_name
                from bank_transactions bt
                left join banks bk on bk.id = bt.bank_id
                where bt.status = 'a'
                and bt.type = 'debit'
                " . (empty($bankId) ? "" : " and bt.bank_id = '$bankId'") . "
                " . ($branchId == null ? "" : " and bt.branch_id = '$branchId'") . "
                
                UNION
                select
                'f' as sequence,
                spp.id,
                spp.date,
                spp.created_at,
                concat('Supplier Payment - ', spp.invoice) as description,
                spp.amount as withdraw,
                0 as deposit,
                0 as balance,
                concat_ws(' - ', bk.name, bk.bank_name) as bank_name
                from payments spp
                left join banks bk on bk.id = spp.bank_id
                where spp.status = 'a'
                and spp.type = 'supplier'
                and spp.payment_method = 'bank'
                " . (empty($bankId) ? "" : " and spp.bank_id = '$bankId'") . "
                " . ($branchId == null ? "" : " and spp.branch_id = '$branchId'") . "

                UNION
                select
                'g' as sequence,
                cpp.id,
                cpp.date,
                cpp.created_at,
                concat('Customer Payment - ', cpp.invoice) as description,
                cpp.amount as withdraw,
                0 as deposit,
                0 as balance,
                concat_ws(' - ', bk.name, bk.bank_name) as bank_name
                from payments cpp
                left join banks bk on bk.id = cpp.bank_id
                where cpp.status = 'a'
                and cpp.type = 'customer'
                and cpp.payment_method = 'bank'
                " . (empty($bankId) ? "" : " and cpp.bank_id = '$bankId'") . "
                " . ($branchId == null ? "" : " and cpp.branch_id = '$branchId'") . "

                UNION
                select
                'h' as sequence,
                prp.id,
                prp.date,
                prp.created_at,
                concat('Provider Payment - ', prp.invoice) as description,
                prp.amount as withdraw,
                0 as deposit,
                0 as balance,
                concat_ws(' - ', bk.name, bk.bank_name) as bank_name
                from payments prp
                left join banks bk on bk.id = prp.bank_id
                where prp.status = 'a'
                and prp.type = 'provider'
                and prp.payment_method = 'bank'
                " . (empty($bankId) ? "" : " and prp.bank_id = '$bankId'") . "
                " . ($branchId == null ? "" : " and prp.branch_id = '$branchId'") . "

                UNION
                select
                'p' as sequence,
                emp.id,
                emp.date,
                emp.created_at,
                concat('Employee Payment - ', emp.invoice) as description,
                emp.amount as withdraw,
                0 as deposit,
                0 as balance,
                concat_ws(' - ', bk.name, bk.bank_name) as bank_name
                from payments emp
                left join banks bk on bk.id = emp.bank_id
                where emp.status = 'a'
                and emp.type = 'employee'
                and emp.payment_method = 'bank'
                " . (empty($bankId) ? "" : " and emp.bank_id = '$bankId'") . "
                " . ($branchId == null ? "" : " and emp.branch_id = '$branchId'") . "

                -- the running balance follows the business date, so a back-dated entry lands where it belongs
                order by date asc, created_at asc, sequence asc, id asc";

        return collect(DB::select($query));
    }

    public function getBankLedger(Request $request)
    {
        $branchId = $this->branchId;
        $ledgers = $this->bankLedgerRows($branchId, $request->bankId);

        if (empty($request->bankId)) {
            $previousBalance = Bank::where('branch_id', $branchId)->sum('balance');
        } else {
            $supplier = Bank::select('balance')->where('id', $request->bankId)
                ->where('branch_id', $branchId)
                ->first();
            $previousBalance = empty($supplier) ? 0 : $supplier->balance;
        }

        $ledgers = $ledgers->map(function ($ledger, $key) use ($previousBalance, $ledgers) {
            $lastBalance = $key == 0 ? $previousBalance : $ledgers[$key - 1]->balance;
            $ledger->balance = ($lastBalance + $ledger->deposit) - $ledger->withdraw;
            return $ledger;
        });

        $previousLedger = collect($ledgers)->filter(function ($ledger) use ($request) {
            return $ledger->date < $request->dateFrom;
        });
        $previousBalance = $previousLedger->isNotEmpty() ? $previousLedger->last()->balance : $previousBalance;

        if (!empty($request->dateFrom) && !empty($request->dateTo)) {
            $ledgers = $ledgers->filter(function ($ledger) use ($request) {
                return $ledger->date >= $request->dateFrom && $ledger->date <= $request->dateTo;
            })->values();
        }


        return response()->json(['previousBalance' => $previousBalance, 'ledgers' => $ledgers]);
    }

    // Per-amount drill-down for the Day Book's Opening/Receipt/Payment/Closing bank cells —
    // each cell must show only the entries behind that specific number, not the whole ledger.
    public function getDayBookBankDetail(Request $request)
    {
        $branchId = $this->branchId;
        $bankId = $request->bankId;
        $section = $request->section; // 'opening' | 'receipt' | 'payment' | 'closing'
        $dateFrom = $request->dateFrom;
        $dateTo = $request->dateTo;

        if (in_array($section, ['opening', 'closing'])) {
            $date = $section === 'opening' ? Carbon::parse($dateFrom)->subDay()->format('Y-m-d') : $dateTo;
            $rows0 = Bank::getBankBalance(['branchId' => $branchId, 'bankId' => $bankId], $date);
            $row = $rows0[0] ?? null;

            if (!$row) {
                return response()->json(['type' => 'breakdown', 'asOfDate' => $date, 'total' => 0, 'rows' => []]);
            }

            $labels = [
                'total_credit' => ['Bank Deposit', 'in'],
                'total_receive_customer' => ['Customer Bank Received', 'in'],
                'total_receive_supplier' => ['Supplier Bank Received (Return)', 'in'],
                'total_debit' => ['Bank Withdraw', 'out'],
                'total_paid_supplier' => ['Supplier Payment (Bank)', 'out'],
                'total_paid_provider' => ['Provider Payment (Bank)', 'out'],
                'total_paid_customer' => ['Customer Payment (Bank)', 'out'],
                'total_paid_employee' => ['Employee Payment (Bank)', 'out'],
            ];

            $rows = [];
            foreach ($labels as $key => [$label, $direction]) {
                $amount = (float) ($row->$key ?? 0);
                if ($amount != 0) {
                    $rows[] = ['label' => $label, 'direction' => $direction, 'amount' => $amount];
                }
            }
            $baseBalance = (float) (Bank::where('id', $bankId)->value('balance') ?? 0);
            if ($baseBalance != 0) {
                $rows[] = ['label' => 'Opening Adjustment (Account Balance)', 'direction' => 'in', 'amount' => $baseBalance];
            }

            return response()->json([
                'type' => 'breakdown',
                'asOfDate' => $date,
                'total' => (float) $row->currentbalance,
                'rows' => $rows,
            ]);
        }

        // receipt / payment: the individual bank-in or bank-out transactions within the range
        $direction = $section === 'payment' ? 'out' : 'in';
        $rows = $this->bankLedgerRows($branchId, $bankId)
            ->filter(function ($row) use ($dateFrom, $dateTo, $direction) {
                $amount = $direction === 'in' ? (float) $row->deposit : (float) $row->withdraw;
                return $amount != 0 && $row->date >= $dateFrom && $row->date <= $dateTo;
            })
            ->map(function ($row) use ($direction) {
                return [
                    'date' => $row->date,
                    'description' => $row->description,
                    'amount' => $direction === 'in' ? (float) $row->deposit : (float) $row->withdraw,
                ];
            })
            ->sortBy('date')
            ->values();

        return response()->json(['type' => 'list', 'rows' => $rows]);
    }
}
