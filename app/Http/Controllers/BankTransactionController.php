<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\BankTransaction;
use App\Http\Requests\BankTransactionRequest;

class BankTransactionController extends Controller
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
        $transactions = BankTransaction::with('adUser', 'upUser', 'bank')->where('branch_id', $this->branchId);
        if (!empty($request->transactionId)) {
            $transactions->where('id', $request->transactionId);
        }
        if (!empty($request->bankId)) {
            $transactions->where('bank', $request->bankId);
        }
        if (!empty($request->type)) {
            $transactions->where('type', $request->type);
        }
        if (!empty($request->dateFrom) && !empty($request->dateTo)) {
            $transactions->whereBetween('date', [$request->dateFrom, $request->dateTo]);
        }
        $transactions = $transactions->latest()->get();
        return response()->json($transactions);
    }

    public function create()
    {
        if (!checkAccess('bankTransaction')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Account/BankTransaction', [
            'invoice' => invoiceGenerate('Bank_Transaction', 'T', $this->branchId),
        ]);
    }

    public function store(BankTransactionRequest $request)
    {
        if (!$request->validated()) return send_error("Validation Error", $request->validated(), 422);
        try {
            $invoice = BankTransaction::where('invoice', $request->invoice)->first();
            if (empty($invoice)) {
                $invoice = invoiceGenerate('Bank_Transaction', 'T', $this->branchId);
            }
            $data = new BankTransaction();
            $data->invoice = $invoice;
            $dataKey = $request->except('id');
            foreach ($dataKey as $key => $value) {
                $data[$key] = $value;
            }
            $data->created_by = $this->userId;
            $data->ipAddress = request()->ip();
            $data->branch_id = $this->branchId;
            $data->save();

            $msg = "BankTransaction has created successfully";
            return response()->json(['status' => true, 'message' => $msg, 'invoice' => invoiceGenerate('Bank_Transaction', 'T', $this->branchId)]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function update(BankTransactionRequest $request)
    {
        if (!$request->validated()) return send_error("Validation Error", $request->validated(), 422);
        try {
            $data = BankTransaction::find($request->id);
            $dataKey = $request->except('id');
            foreach ($dataKey as $key => $value) {
                $data[$key] = $value;
            }
            $data->updated_by = $this->userId;
            $data->updated_at = Carbon::now();
            $data->ipAddress = request()->ip();
            $data->branch_id = $this->branchId;
            $data->update();

            $msg = "BankTransaction has updated successfully";
            return response()->json(['status' => true, 'message' => $msg, 'invoice' => invoiceGenerate('Bank_Transaction', 'T', $this->branchId)]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function destroy(Request $request)
    {
        try {
            $data = BankTransaction::find($request->id);
            $data->deleted_by = $this->userId;
            $data->status = 'd';
            $data->ipAddress = request()->ip();
            $data->update();

            $data->delete();
            return response()->json(['status' => true, 'message' => "BankTransaction has deleted successfully"]);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }

    public function deletedBankTransactionRecord()
    {
        if (!checkAccess('bankTransactionRestore')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Account/BankTransaction/DeletedRecord');
    }

    public function getDeletedBankTransaction(Request $request)
    {
        $transactions = BankTransaction::onlyTrashed()->with('deUser', 'bank')->where('branch_id', $this->branchId);
        if (!empty($request->search)) {
            $transactions = $transactions->where('invoice', 'like', '%' . $request->search . '%');
        }
        $transactions = $transactions->latest('deleted_at')->get()->map(function ($item) {
            $item->deleted_by_name = $item->deUser->name ?? 'NA';
            return $item;
        });
        return response()->json($transactions);
    }

    public function restoreBankTransaction(Request $request)
    {
        if (!checkAccess('bankTransactionRestore')) {
            return send_error('You are not authorized to restore bank transaction', null, 403);
        }
        $transaction = BankTransaction::onlyTrashed()->where('branch_id', $this->branchId)->find($request->id);
        if (empty($transaction)) {
            return send_error('Deleted bank transaction not found', null, 404);
        }
        try {
            $transaction->restore();
            $transaction->status = 'a';
            $transaction->deleted_by = null;
            $transaction->ipAddress = request()->ip();
            $transaction->update();

            return response()->json(['status' => true, 'message' => "BankTransaction has restored successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }
}
