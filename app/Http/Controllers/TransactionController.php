<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class TransactionController extends Controller
{
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
        $transactions = Transaction::with('adUser', 'upUser', 'account')->where('branch_id', $this->branchId);
        if (!empty($request->transactionId)) {
            $transactions->where('id', $request->transactionId);
        }
        if (!empty($request->accountId)) {
            $transactions->where('account_id', $request->accountId);
        }
        if (!empty($request->type)) {
            $transactions->where('type', $request->type);
        }
        if (!empty($request->dateFrom) && !empty($request->dateTo)) {
            $transactions->whereBetween('date', [$request->dateFrom, $request->dateTo]);
        }
        $transactions = $transactions->where('status','a')->latest()->get();
        return response()->json($transactions);
    }

    public function expense()
    {
        if (!checkAccess('expense')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Account/TransactionForm', [
            'title' => 'Expense Entry',
            'type' => 'expense',
            'invoice' => transactionInvoice('Transaction', 'T', session('branch')->id, 'expense'),
        ]);
    }

    public function income()
    {
        if (!checkAccess('income')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Account/TransactionForm', [
            'title' => 'Income Entry',
            'type' => 'income',
            'invoice' => transactionInvoice('Transaction', 'T', session('branch')->id, 'income'),
        ]);
    }

    public function getInvoice(Request $request)
    {
        $type = $request->type === 'income' ? 'income' : 'expense';
        return response()->json(['invoice' => transactionInvoice('Transaction', 'T', $this->branchId, $type)]);
    }

    public function exportExcel(Request $request)
    {
        $type = $request->type === 'income' ? 'income' : 'expense';
        if (!checkAccess($type)) {
            abort(403);
        }

        $transactions = Transaction::with('account')
            ->where('branch_id', $this->branchId)
            ->where('status', 'a')
            ->where('type', $type)
            ->when($request->dateFrom && $request->dateTo, fn ($q) => $q->whereBetween('date', [$request->dateFrom, $request->dateTo]))
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($qq) use ($request) {
                    $qq->where('invoice', 'like', '%' . $request->search . '%')
                        ->orWhere('note', 'like', '%' . $request->search . '%');
                });
            })
            ->latest()
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(ucfirst($type) . ' List');

        $headers = ['Sl', 'Invoice', 'Date', 'Account', 'Amount', 'Note'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

        $row = 2;
        foreach ($transactions as $index => $item) {
            $sheet->fromArray([
                $index + 1, $item->invoice, $item->date, optional($item->account)->name, $item->amount, $item->note,
            ], null, 'A' . $row);
            $row++;
        }

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fileName = $type . '-list-' . now()->format('Y-m-d') . '.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }


    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'invoice' => 'required',
            'type' => 'required',
            'date' => 'required',
            'account_id' => 'required',
            'amount' => 'required',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $invoice = Transaction::where('invoice', $request->invoice)->first();
            if (empty($invoice)) {
                $invoice = transactionInvoice('Transaction', 'T', $this->branchId, $request->type);
            }
            $data = new Transaction();
            $data->invoice = $invoice;
            $dataKey = $request->except('id');
            foreach ($dataKey as $key => $value) {
                $data[$key] = $value;
            }
            $data->created_by = $this->userId;
            $data->ipAddress = request()->ip();
            $data->branch_id = $this->branchId;
            $data->save();

            if ($request->type == 'expense') {
                $msg = "Expense has created successfully";
            } else {
                $msg = "Income has created successfully";
            }
            return response()->json(['status' => true, 'message' => $msg, 'id' => $data->id, 'invoice' => transactionInvoice('Transaction', 'T', $this->branchId, $request->type)]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'invoice' => 'required',
            'type' => 'required',
            'date' => 'required',
            'account_id' => 'required',
            'amount' => 'required',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $data = Transaction::find($request->id);
            $dataKey = $request->except('id');
            foreach ($dataKey as $key => $value) {
                $data[$key] = $value;
            }
            $data->updated_by = $this->userId;
            $data->updated_at = Carbon::now();
            $data->ipAddress = request()->ip();
            $data->branch_id = $this->branchId;
            $data->update();

            if ($request->type == 'expense') {
                $msg = "Expense has updated successfully";
            } else {
                $msg = "Income has updated successfully";
            }
            return response()->json(['status' => true, 'message' => $msg, 'invoice' => transactionInvoice('Transaction', 'T', $this->branchId, $request->type)]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function destroy(Request $request)
    {
        try {
            $data = Transaction::find($request->id);
            $data->deleted_by = $this->userId;
            $data->status = 'd';
            $data->ipAddress = request()->ip();
            $data->update();

            $data->delete();
            if ($request->type == 'expense') {
                $msg = "Expense has deleted successfully";
            } else {
                $msg = "Income has deleted successfully";
            }
            return response()->json(['status' => true, 'message' => $msg]);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }
}
